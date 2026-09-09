@once
    <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/super-build/ckeditor.js"></script>
    <script>
        function RichTextUploadAdapter(loader) {
            this.loader = loader;
        }

        RichTextUploadAdapter.prototype.upload = function () {
            return this.loader.file.then(function (file) {
                return new Promise(function (resolve, reject) {
                    const body = new FormData();
                    body.append('upload', file);

                    fetch('{{ route('admin.rich-text-uploads.store') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                        },
                        body: body,
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            if (data.url) {
                                resolve({ default: data.url });
                            } else {
                                reject(data.message || 'Upload failed.');
                            }
                        })
                        .catch(() => reject('Upload failed.'));
                });
            });
        };

        RichTextUploadAdapter.prototype.abort = function () {};

        function initRichTextEditors() {
            document.querySelectorAll('textarea[data-rich-text]').forEach(function (textarea) {
                if (textarea.dataset.richTextReady) {
                    return;
                }
                textarea.dataset.richTextReady = '1';

                CKEDITOR.ClassicEditor.create(textarea, {
                    // The CDN "super-build" bundles CKEditor's premium/cloud-only
                    // features (collaboration, CKBox, WProofreader, etc.) baked in;
                    // without a license they only get in the way (they reject
                    // editor creation entirely if left enabled), so they're removed.
                    removePlugins: [
                        'AIAssistant', 'CKBox', 'CKFinder', 'EasyImage',
                        'RealTimeCollaborativeEditing',
                        'RealTimeCollaborativeComments',
                        'RealTimeCollaborativeTrackChanges',
                        'RealTimeCollaborativeRevisionHistory',
                        'PresenceList', 'Comments', 'TrackChanges', 'TrackChangesData',
                        'RevisionHistory', 'Pagination', 'WProofreader', 'MathType',
                        'SlashCommand', 'Template', 'DocumentOutline', 'FormatPainter',
                        'TableOfContents', 'PasteFromOfficeEnhanced', 'CaseChange',
                        'MultiLevelList',
                    ],
                    extraPlugins: [
                        function (editor) {
                            editor.plugins.get('FileRepository').createUploadAdapter = function (loader) {
                                return new RichTextUploadAdapter(loader);
                            };
                        },
                    ],
                    toolbar: {
                        items: [
                            'heading', '|',
                            'bold', 'italic', 'underline', 'strikethrough', '|',
                            'link', 'bulletedList', 'numberedList', 'indent', 'outdent', '|',
                            'alignment', 'fontColor', 'fontBackgroundColor', 'fontSize', '|',
                            'blockQuote', 'insertTable', 'horizontalLine', 'specialCharacters', 'mediaEmbed', 'uploadImage', '|',
                            'undo', 'redo',
                        ],
                        shouldNotGroupWhenFull: true,
                    },
                }).then(function (editor) {
                    textarea.ckEditorInstance = editor;
                    editor.model.document.on('change:data', function () {
                        textarea.value = editor.getData();
                    });
                });
            });
        }

        document.addEventListener('DOMContentLoaded', initRichTextEditors);
    </script>
@endonce
