# Codiad-Download

Adds **Download** to the file tree context menu for *every* file, including
projects whose root is an absolute path 

## Why it exists

Core already has a Download entry, but it is hidden for absolute ("external")
project roots: `components/filemanager/init.js` adds `.no-external` to it and
hides it whenever `codiad.project.isAbsPath(root)` is true. Even if it were
shown, `components/filemanager/download.php` cannot serve those paths - it
rejects `:` and `\` as illegal filename characters and prefixes every path with
`WORKSPACE`. So external projects had no download at all.

This plugin renders its own entry backed by `controller.php`, which accepts both
absolute and workspace relative paths.

## Behaviour

* The entry appears for file nodes only (never for directories or the root).
* When core's own Download entry is visible - i.e. the project lives inside the
  Codiad workspace - this entry hides itself, so you never see two identical
  "Download" items. Core's endpoint is used in that case.
* The file is streamed through the hidden `#download` iframe, so the editor
  layout and open files are untouched.

## How it works

| File | Role |
| --- | --- |
| `plugin.json` | Registers the context menu entry `file-only` / `icon-download`. |
| `init.js` | Tags the entry, decides when to show it, builds the request URL (path is URL encoded) and points `#download` at it. |
| `controller.php` | Session check, path resolution (absolute *or* relative to the active project), authorisation (`checkPath()` plus containment in the active project), then force-download headers and `fpassthru()`. |

`controller.php` normalises `\` to `/`, refuses any path containing `..`,
resolves the path with `realpath()` *before* the checks, and answers errors
through `parent.codiad.message.error()` because the request is made from the
hidden iframe.

## Install

Copy the `Codiad-Download` folder into `plugins/` and reload the page (Ctrl+F5
to bypass the cached `index.php` plugin list).

## License
Codiad-Download is released under [MIT License](https://github.com/chezinut/Codiad-Download/blob/main/LICENSE)

