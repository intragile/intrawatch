import type { RouteLocationRaw } from 'vue-router'

/**
 * The location of a manual chapter. Built as a path because a `help.chapter` params object
 * would encode the slash of `section/chapter` as `%2F`; the path still resolves to that name.
 */
export function chapterLocation(slug: string): RouteLocationRaw {
  return { path: `/help/${slug.split('/').map(encodeURIComponent).join('/')}` }
}

/** Letters that have no combining-mark decomposition, folded as the backend's Ascii::fold does. */
const EXTRA_FOLDS: Readonly<Record<string, string>> = {
  ø: 'o',
  Ø: 'o',
  ł: 'l',
  Ł: 'l',
  đ: 'd',
  Đ: 'd',
  ħ: 'h',
  Ħ: 'h',
  ı: 'i',
}

function foldChar(char: string): string {
  const base = EXTRA_FOLDS[char] ?? char.normalize('NFD').replace(/\p{Mn}/gu, '')
  return base.toLowerCase()
}

/** Accent-insensitive, lowercase form of a text (the search backend matches on the same form). */
export function fold(text: string): string {
  return Array.from(text, foldChar).join('')
}

/** The backend's default `min_term_length`: shorter query words are not searched, so not marked. */
const MIN_TERM_LENGTH = 3

export function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * Escapes a plain-text search snippet, then wraps the query words in `<mark>`. Words match
 * accent- and case-insensitively, so "ertesites" marks "Értesítés".
 */
export function highlight(snippet: string, query: string): string {
  const terms = [
    ...new Set(fold(query).split(/[^a-z0-9]+/).filter(t => t.length >= MIN_TERM_LENGTH)),
  ]

  // Folding can change a character's length, so every folded character remembers the
  // original character (by UTF-16 offset range) it came from.
  let folded = ''
  const starts: number[] = []
  const ends: number[] = []
  let offset = 0
  for (const char of Array.from(snippet)) {
    const f = foldChar(char)
    for (let i = 0; i < f.length; i++) {
      starts.push(offset)
      ends.push(offset + char.length)
    }
    folded += f
    offset += char.length
  }

  const ranges: Array<[number, number]> = []
  for (const term of terms) {
    let at = folded.indexOf(term)
    while (at !== -1) {
      ranges.push([starts[at] as number, ends[at + term.length - 1] as number])
      at = folded.indexOf(term, at + term.length)
    }
  }
  ranges.sort((a, b) => a[0] - b[0])
  const merged: Array<[number, number]> = []
  for (const [start, end] of ranges) {
    const last = merged.at(-1)
    if (last && start <= last[1])
      last[1] = Math.max(last[1], end)
    else
      merged.push([start, end])
  }

  let html = ''
  let cursor = 0
  for (const [start, end] of merged) {
    html += `${escapeHtml(snippet.slice(cursor, start))}<mark>${escapeHtml(snippet.slice(start, end))}</mark>`
    cursor = end
  }
  return html + escapeHtml(snippet.slice(cursor))
}

/** Whether the visitor asked the system to reduce motion. */
export function prefersReducedMotion(): boolean {
  return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}
