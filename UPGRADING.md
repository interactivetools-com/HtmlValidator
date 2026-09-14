# Upgrading HtmlValidator

Most old code keeps working after an upgrade:

- **If it breaks, it tells you.** Old names phase out over multiple
  releases - IDE strikethrough, then a quietly logged notice with your file
  and line (CMS Builder shows these in the Developer Log), then a clear
  error - always naming the replacement.
- **Everything worth checking is listed here.** Silent behavior changes,
  deprecations, and optional renames, per version, each with a search that
  finds affected code.

Error codes are never renamed once released, so code that switches on
`$violation->code` needs no changes between versions. Message wording can
change; match on the code, not the message.

Full lists of what changed per release: [CHANGELOG.md](CHANGELOG.md).

---

No release has needed an upgrade check yet.
