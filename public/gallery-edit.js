// Runs after the page's scripts (Bootstrap, the admin helpers) have loaded, as the other services' scripts do
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const config = window.OsmiumGalleryConfig || {};
    const actionUrl = config.basePath + 'gallery/action/';

    function byId(id) {
        return document.getElementById(id);
    }

    function show(kind, message) {
        const box = byId('galleryResult');
        box.className = 'alert alert-' + kind;
        box.textContent = message;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function post(fields, file) {
        const form = new FormData();
        form.append('csrf_token', window.csrfToken);
        Object.keys(fields).forEach(function (key) {
            const value = fields[key];
            if (Array.isArray(value)) value.forEach(function (item) { form.append(key + '[]', item); });
            else form.append(key, value);
        });
        if (file) form.append('image', file);

        const response = await fetch(actionUrl, { method: 'POST', body: form });
        return response.json();
    }

    // ----- Album details -----

    const albumForm = byId('albumForm');
    const albumId = Number(byId('albumId').value);

    async function saveAlbum(e) {
        e.preventDefault();
        const button = byId('albumSaveButton');
        button.disabled = true;
        const fields = {
            action: albumId ? 'update_album' : 'create_album',
            id: albumId,
            name: byId('albumName').value,
            event_date: byId('albumDate').value,
            blurb: byId('albumBlurb').value,
        };
        const published = byId('albumPublished');
        if (published && published.checked) fields.published = '1';

        try {
            const result = await post(fields);
            if (result.success) {
                window.location.href = config.editUrl + '?id=' + result.id;
                return;
            }
            show('danger', result.error || 'Failed to save');
        } catch (error) {
            show('danger', 'An error occurred while saving');
        }
        button.disabled = false;
    }

    albumForm.addEventListener('submit', saveAlbum);
    if (!albumId) return; // A new album has no photos until it is saved

    // ----- Upload: one request per file, so a refused file is named with its reason -----

    const drop = byId('galleryDrop');
    const fileInput = byId('galleryFiles');
    const log = byId('uploadLog');

    function logLine(text, kind) {
        const line = document.createElement('li');
        line.className = 'text-' + kind;
        line.textContent = text;
        log.appendChild(line);
        return line;
    }

    // Returns true when the photo was added
    async function uploadOne(file) {
        const line = logLine(file.name + ': uploading...', 'muted');
        try {
            const result = await post({ action: 'upload', id: albumId }, file);
            if (result.success) {
                line.className = 'text-success';
                line.textContent = file.name + ': added';
                return true;
            }
            line.className = 'text-danger';
            line.textContent = file.name + ': not added. ' + (result.error || 'Unknown error');
        } catch (error) {
            line.className = 'text-danger';
            line.textContent = file.name + ': not added. The upload failed (the file may be bigger than the server allows).';
        }
        return false;
    }

    // The lists are server-rendered, so they reload to show new photos; when a file was refused the page stays so the reasons can be read
    async function uploadAll(files) {
        log.innerHTML = '';
        let added = 0;
        let refused = 0;
        for (const file of Array.from(files)) {
            const ok = await uploadOne(file); // In turn: the server resizes each photo, and a failure should not stop the rest
            if (ok) added++; else refused++;
        }
        fileInput.value = '';
        if (added > 0 && refused === 0) window.location.reload();
        if (added > 0 && refused > 0) {
            const link = document.createElement('li');
            link.innerHTML = '<a href="#" class="fw-medium">Reload to see the photos that were added</a>';
            link.firstChild.addEventListener('click', function (e) { e.preventDefault(); window.location.reload(); });
            log.appendChild(link);
        }
    }

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (name) {
        drop.addEventListener(name, function (e) { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter', 'dragover'].forEach(function (name) {
        drop.addEventListener(name, function () { drop.classList.add('drag-active'); });
    });
    ['dragleave', 'drop'].forEach(function (name) {
        drop.addEventListener(name, function () { drop.classList.remove('drag-active'); });
    });
    drop.addEventListener('drop', function (e) { uploadAll(e.dataTransfer.files); });
    drop.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () { uploadAll(fileInput.files); });

    // ----- Caption and alt text: the list's Edit action opens the form -----

    const photoModal = bootstrap.Modal.getOrCreateInstance(byId('photoModal'));
    let editingId = 0;

    document.addEventListener('list:action', function (e) {
        if (e.detail.action !== 'edit_photo') return;
        const photo = (window.OsmiumGalleryPhotos || {})[e.detail.id];
        if (!photo) return;
        editingId = e.detail.id;
        byId('photoCaption').value = photo.caption || '';
        byId('photoAlt').value = photo.alt || '';
        photoModal.show();
    });

    byId('photoForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const result = await post({ action: 'update_media', id: editingId, caption: byId('photoCaption').value, alt: byId('photoAlt').value });
        if (result.success) {
            window.location.reload();
            return;
        }
        photoModal.hide();
        show('danger', result.error || 'Could not save the text');
    });
});
