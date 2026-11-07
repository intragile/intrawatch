<?php

namespace App\Providers;

use Amp\Dns\DnsResolver;
use App\Alerting\Transport\AddressResolver;
use App\Alerting\Transport\SystemAddressResolver;
use App\Enums\MonitorType;
use App\Models\Workspace;
use App\Monitoring\Checks\CheckerRegistry;
use App\Monitoring\Checks\DnsChecker;
use App\Monitoring\Checks\DomainChecker;
use App\Monitoring\Checks\Http\CertificateFacts;
use App\Monitoring\Checks\Http\DomainFacts;
use App\Monitoring\Checks\Http\DomainFactsProvider;
use App\Monitoring\Checks\Http\TlsFactsProvider;
use App\Monitoring\Checks\HttpChecker;
use App\Monitoring\Checks\PingChecker;
use App\Monitoring\Checks\TcpChecker;
use App\Monitoring\Checks\TlsChecker;
use App\Monitoring\Domain\DomainExpiryService;
use App\Monitoring\Domain\RdapBootstrap;
use App\Monitoring\Domain\RdapClient;
use App\Monitoring\Domain\RegistrableDomains;
use App\Monitoring\Domain\WhoisClient;
use App\Monitoring\Engine\DatabaseDeadlineEvaluator;
use App\Monitoring\Engine\DatabaseGroupEvaluator;
use App\Monitoring\Engine\DeadlineEvaluator;
use App\Monitoring\Engine\GroupEvaluator;
use App\Monitoring\Engine\MaintenanceOracle;
use App\Monitoring\Net\GuardedConnector;
use App\Monitoring\Net\NetworkPolicy;
use App\Monitoring\Worker\Clock;
use App\Monitoring\Worker\SystemClock;
use App\Policies\WorkspacePolicy;
use App\Status\Maintenance\CalendarMaintenanceOracle;
use App\Status\Maintenance\MaintenanceCalendarLoader;
use App\Support\CurrentWorkspace;
use App\Support\RecordAuthEvent;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->singleton(MaintenanceOracle::class, fn (Application $app): MaintenanceOracle => new CalendarMaintenanceOracle(
            MaintenanceCalendarLoader::fromConfig(),
            $app->make(Clock::class),
            config()->integer('status.maintenance.stamp_seconds', 5),
            config()->integer('status.maintenance.reload_seconds', 60),
        ));
        $this->app->bind(GroupEvaluator::class, DatabaseGroupEvaluator::class);
        $this->app->bind(DeadlineEvaluator::class, DatabaseDeadlineEvaluator::class);
        $this->app->singleton(CheckerRegistry::class);
        $this->app->resolving(CheckerRegistry::class, function (CheckerRegistry $registry): void {
            $registry->register(MonitorType::Http, HttpChecker::class);
            $registry->register(MonitorType::Tcp, TcpChecker::class);
            $registry->register(MonitorType::Ping, PingChecker::class);
            $registry->register(MonitorType::Dns, DnsChecker::class);
            $registry->register(MonitorType::Tls, TlsChecker::class);
            $registry->register(MonitorType::Domain, DomainChecker::class);
        });
        $this->app->singleton(DnsResolver::class, fn (): DnsResolver => \Amp\Dns\dnsResolver());
        $this->app->singleton(NetworkPolicy::class, fn (): NetworkPolicy => NetworkPolicy::fromConfig());
        $this->app->singleton(GuardedConnector::class);
        $this->app->bind(AddressResolver::class, SystemAddressResolver::class);
        $this->app->bind(TlsFactsProvider::class, CertificateFacts::class);
        $this->app->singleton(RegistrableDomains::class, fn (): RegistrableDomains => RegistrableDomains::load());
        $this->app->singleton(RdapBootstrap::class, fn (): RdapBootstrap => RdapBootstrap::load());
        // One instance per process, so concurrent lookups of a domain share one registry query and one cache.
        $this->app->singleton(DomainExpiryService::class, function (Application $app): DomainExpiryService {
            // Registries and IANA are public: their traffic, redirects and referrals included, never reaches a
            // private or loopback address.
            $registries = new GuardedConnector($app->make(DnsResolver::class), NetworkPolicy::forRegistries());

            return new DomainExpiryService(
                $app->make(RegistrableDomains::class),
                $app->make(RdapBootstrap::class),
                new RdapClient($registries),
                new WhoisClient($registries),
                $app->make(Clock::class),
                self::whoisServers(),
                config()->string('monitoring.domain.iana_whois', 'whois.iana.org'),
                (float) config()->integer('monitoring.domain.lookup_timeout', 30),
            );
        });
        $this->app->bind(DomainFactsProvider::class, DomainFacts::class);
        $this->app->singleton(Clock::class, SystemClock::class);
    }

    /**
     * Configured WHOIS servers by lowercase TLD.
     *
     * @return array<string, string>
     */
    private static function whoisServers(): array
    {
        $servers = [];
        foreach ((array) config('monitoring.domain.whois_servers', []) as $tld => $server) {
            if (is_string($tld) && is_string($server) && $server !== '') {
                $servers[strtolower($tld)] = $server;
            }
        }

        return $servers;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(10));

        Gate::policy(Workspace::class, WorkspacePolicy::class);

        // Heartbeats are limited per token. Misses (unknown token, inactive monitor, wrong type) are limited per
        // client address in the controller, after the lookup, so they never block a valid token.
        RateLimiter::for('push', function (Request $request): Limit {
            $token = $request->route('token');

            return Limit::perMinute(120)->by('token:'.(is_string($token) ? $token : ''));
        });

        RateLimiter::for('notification-test', fn (Request $request): Limit => Limit::perMinute(10)->by('user:'.($request->user()->id ?? $request->ip())));

        Event::listen(Login::class, [RecordAuthEvent::class, 'handleLogin']);
        Event::listen(Failed::class, [RecordAuthEvent::class, 'handleFailed']);
        Event::listen(TwoFactorAuthenticationEnabled::class, [RecordAuthEvent::class, 'handleTwoFactorEnabled']);
        Event::listen(TwoFactorAuthenticationDisabled::class, [RecordAuthEvent::class, 'handleTwoFactorDisabled']);

        // The spec is served from the API itself; Scramble's own UI and JSON routes stay off.
        Scramble::configure()
            ->expose(false)
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->title = config()->string('app.name');
                $openApi->info->version = config()->string('app.version');
                // A relative server keeps the exported document independent of APP_URL.
                $openApi->servers = [new Server('/api/v1')];
                // Stable ordering keeps the exported document diffable.
                usort($openApi->paths, fn ($a, $b): int => strcmp($a->path, $b->path));
            });
    }
}
