/*
 *  Codiad-Download - context menu "Download" for every file in the file tree.
 *
 *  Core hides its own Download entry whenever the project root is an absolute
 *  path (the ".no-external" rule), and components/filemanager/download.php
 *  refuses those same paths anyway - so for "external" projects there was no
 *  way to download anything. This entry is backed by a controller that
 *  understands both absolute and workspace relative paths.
 */

(function(global, $){

    var codiad = global.codiad,
        scripts = document.getElementsByTagName('script'),
        path = scripts[scripts.length-1].src.split('?')[0],
        curpath = path.split('/').slice(0, -1).join('/') + '/';

    codiad.Download = {

        path: curpath,

        init: function() {
            var menu = $('#context-menu');

            // index.php renders plugin.json entries with no hook of their own,
            // so tag ours (and the separator above it) to be able to toggle it.
            menu.find('a').each(function() {
                var onclick = this.getAttribute('onclick') || '';
                if (onclick.indexOf('codiad.Download.file') !== -1) {
                    $(this).addClass('cdx-download')
                        .prev('hr')
                        .addClass('cdx-download');
                }
            });

            // contextMenuShow() has already applied its .file-only and
            // .no-external rules by the time this fires.
            amplify.subscribe('context-menu.onShow', function(obj) {
                if (!obj || obj.type !== 'file') {
                    return;
                }
                codiad.Download.place();
            });
        },

        ////////////////////////////////////////////////////////////
        //  Show our entry only when core has nothing to offer:
        //  two identical "Download" items in one menu would be worse
        //  than one.
        ////////////////////////////////////////////////////////////
        place: function() {
            var entry = $('#context-menu .cdx-download');
            if (!entry.length) {
                return;
            }
            var core = $('#context-menu a').filter(function() {
                return (this.getAttribute('onclick') || '')
                    .indexOf('codiad.filemanager.download') !== -1;
            });
            // css() instead of :visible - the menu is still fading in
            var coreShown = core.length > 0 && core.css('display') !== 'none';
            if (coreShown) {
                entry.hide();
            } else {
                entry.show();
            }
        },

        ////////////////////////////////////////////////////////////
        //  Download the file behind a context menu entry
        //
        //  Parameter
        //
        //  path - {String} - data-path of the node. Absolute for
        //         "external" projects, workspace relative otherwise.
        ////////////////////////////////////////////////////////////
        file: function(path) {
            if (!path) {
                return;
            }
            this.download(path);
        },

        url: function(path) {
            return this.path + 'controller.php?action=download&path=' +
                encodeURIComponent(path);
        },

        download: function(path) {
            // Timestamp: asking for the same file twice has to re-request it
            var src = this.url(path) + '&t=' + (+new Date());
            var frame = $('#download');
            if (frame.length) {
                // Hidden iframe (index.php) - the attachment headers do the rest
                frame.attr('src', src);
            } else {
                window.location.href = src;
            }
        }
    };

    // Registered after the namespace exists: jQuery runs the callback straight
    // away when the document is already loaded (e.g. a late script injection),
    // and init() must not be reached before codiad.Download is defined.
    $(function() {
        codiad.Download.init();
    });
})(this, jQuery);
