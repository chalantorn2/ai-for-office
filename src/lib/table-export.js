/**
 * Pulls a rendered markdown table out of the DOM and hands it back as a file or
 * as clipboard text.
 *
 * Reading the DOM rather than the markdown AST keeps this independent of how
 * react-markdown structures its children, and it picks up exactly what the user
 * can see — including cells the model wrote with inline formatting.
 */

/** @returns {string[][]} rows of trimmed cell text, header row first */
export function readTable(table) {
  return [...table.rows].map((row) =>
    // A cell may hold a <br> or a list; collapse all of it to one line so the
    // grid stays rectangular.
    [...row.cells].map((cell) => cell.textContent.replace(/\s+/g, ' ').trim()),
  )
}

function csvCell(value) {
  return /[",\n\r]/.test(value) ? `"${value.replaceAll('"', '""')}"` : value
}

export function toCsv(rows) {
  // CRLF is what Excel expects; the BOM is added at Blob time.
  return rows.map((row) => row.map(csvCell).join(',')).join('\r\n')
}

export function toTsv(rows) {
  // Tabs and newlines inside a cell would break the paste into new columns.
  return rows
    .map((row) => row.map((c) => c.replaceAll('\t', ' ')).join('\t'))
    .join('\n')
}

function timestamp() {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return (
    `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}` +
    `-${pad(d.getHours())}${pad(d.getMinutes())}`
  )
}

/**
 * Saves the rows as a CSV that Excel opens directly.
 *
 * The leading BOM is what makes Excel on Windows read the file as UTF-8 —
 * without it Thai text arrives as mojibake.
 */
export function downloadCsv(rows, name = 'nova-table') {
  const blob = new Blob(['﻿', toCsv(rows)], {
    type: 'text/csv;charset=utf-8',
  })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')

  a.href = url
  a.download = `${name}-${timestamp()}.csv`
  a.click()

  URL.revokeObjectURL(url)
}
