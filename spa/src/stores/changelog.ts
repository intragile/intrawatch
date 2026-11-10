import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '../api'
import { readPreference, writePreference } from '../utils/storage'

/** The newest release version this browser has already seen on the changelog page. */
export const CHANGELOG_SEEN_KEY = 'intrawatch.changelog.seen'

/**
 * Whether the installation has a release the user has not looked at yet. The newest version is asked once
 * per page load; any failure just leaves the "new" mark off.
 */
export const useChangelogNews = defineStore('changelogNews', () => {
  const latest = ref<string | null>(null)
  const seen = ref<string | null>(readPreference(CHANGELOG_SEEN_KEY))
  let requested = false

  /** A first visit (nothing stored yet) counts as new, but only when there is a release at all. */
  const hasNews = computed(() => latest.value !== null && latest.value !== seen.value)

  async function check(): Promise<void> {
    if (requested)
      return
    requested = true
    try {
      const { data } = await api.GET('/changelog/latest')
      // The changelog page may already have marked a version seen while this was in flight.
      latest.value ??= data?.data.version ?? null
    }
    catch {}
  }

  function markSeen(version: string): void {
    latest.value = version
    seen.value = version
    writePreference(CHANGELOG_SEEN_KEY, version)
  }

  return {
    latest,
    seen,
    hasNews,
    check,
    markSeen,
  }
})
