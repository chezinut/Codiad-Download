# Codiad-Download

Adds **Download** to the file tree context menu for *every* file, and
**Download as ZIP** for every folder and for the project root, including
projects whose root is an absolute path.

## Why it exists

Core already has a Download entry, but it is hidden for absolute ("external")
project roots: `components/filemanager/init.js` adds `.no-external` to it and
hides it whenever `codiad.project.isAbsPath(root)` is true. Even if it were
shown, `components/filemanager/download.php` cannot serve those paths - it
rejects `:` and `\` as illegal filename characters and prefixes every path with
`WORKSPACE`. So external projects had no download at all.

Core's folder download is a separate problem: it builds the archive with
`ZipArchive`, so it fails outright on hosts without the `zip` extension, and it
names the file after the current time rather than after the folder.

This plugin renders its own entries backed by `controller.php`, which accepts
both absolute and workspace relative paths and does not depend on `zip`.

## Behaviour

* **Files** - the entry appears for file nodes. When core's own Download entry is
  visible - i.e. the project lives inside the Codiad workspace - this entry
  hides itself, so you never see two identical "Download" items. Core's endpoint
  is used in that case.
* **Folders** - "Download as ZIP" appears for directory and root nodes, and
  *replaces* core's folder entry rather than stacking next to it, because core's
  folder item is the one that needs the missing `zip` extension.
* The archive is named `<foldername>.zip` and its entries live under a single
  `<foldername>/` top level folder, so unpacking does not scatter the contents
  into the current directory. Empty folders are preserved.
* A notice is shown while a large tree is being zipped, since the request runs
  inside the hidden iframe.
* Transfers happen through the hidden `#download` iframe, so the editor layout
  and open files are untouched.

## How it works

| File | Role |
| --- | --- |
| `plugin.json` | Registers the context menu entries: `file-only` / `icon-download` and `directory-only` / `icon-zip`. |
| `init.js` | Tags both entries, decides who owns the download slot per node type, builds the request URL (path is URL encoded) and points `#download` at it. |
| `controller.php` | Session check, path resolution (absolute *or* relative to the active project), authorisation (`checkPath()` plus containment in the active project), then force-download headers and `fpassthru()`. Folders are staged as a temporary archive that is deleted by a shutdown handler even if the request dies mid-build. |
| `zip.php` | `cdx_zip_writer`, a dependency-free ZIP writer used when `ZipArchive` is unavailable. |

`controller.php` normalises `\` to `/`, refuses any path containing `..`,
resolves the path with `realpath()` *before* the checks, and answers errors
through `parent.codiad.message.error()` because the request is made from the
hidden iframe.

### The ZIP writer

The writer streams entries as it walks the tree:

* `gzdeflate()` for files up to 15 MB, `STORE` above that so the 32-bit ZIP size
  limit is never reached and memory stays flat.
* Deflated files are read once and written straight from memory; larger or
  already-compressed files (`.zip`, `.jpg`, `.mp4`, ...) are `STORE`d in 256 KB
  chunks, so a multi-gigabyte file costs no memory.
* CRC-32 uses the native `crc32()` / `hash_file()`. A hand-rolled table is
  *not* usable here: this is a 32-bit PHP (`PHP_INT_SIZE=4`), where the literal
  `0xEDB88320` overflows to a double and silently corrupts the table.
* Symlinked entries are skipped, which also makes link cycles impossible.
* The general purpose bit 11 (UTF-8) is set only for names containing high
  bytes, so non-ASCII folder names survive the round trip.

## Install

Copy the `Codiad-Download` folder into `plugins/` and reload the page (Ctrl+F5
to bypass the cached `index.php` plugin list).

## License
Codiad-Download is released under [MIT License](https://github.com/chezinut/Codiad-Download/blob/main/LICENSE)
