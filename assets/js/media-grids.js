/**
 * assets/js/media-grids.js
 *
 * Renders window.PHOTO_ALBUM into #photo-album-grid and/or
 * window.VIDEO_GALLERY into #video-gallery-grid — whichever exists on
 * the page. Same "add an entry by copying one object" pattern as
 * assets/js/visit-sites.js.
 *
 * PHOTO_ALBUM entries: { caption, leg, src }
 * VIDEO_GALLERY entries: { title, youtubeId }
 */

(function () {
    function photoCard(entry) {
        const img = entry.src
            ? `<img src="${entry.src}" alt="${entry.caption}" loading="lazy" onerror="this.parentElement.outerHTML='<div class=&quot;ph&quot;><span>Photo pending</span></div>'">`
            : '';
        return `
            <figure>
                <div class="ph">${entry.src ? img : '<span>Photo pending</span>'}</div>
                <figcaption>${entry.caption}${entry.leg ? ' — ' + entry.leg : ''}</figcaption>
            </figure>`;
    }

    function videoCard(entry) {
        if (!entry.youtubeId) {
            return `
                <figure>
                    <div class="ph"><span>Video pending</span></div>
                    <figcaption>${entry.title}</figcaption>
                </figure>`;
        }
        return `
            <figure>
                <div class="ph" style="aspect-ratio:16/9;">
                    <iframe width="100%" height="100%" style="border:0;display:block;"
                        src="https://www.youtube.com/embed/${entry.youtubeId}"
                        title="${entry.title}" loading="lazy"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen></iframe>
                </div>
                <figcaption>${entry.title}</figcaption>
            </figure>`;
    }

    const photoGrid = document.getElementById('photo-album-grid');
    if (photoGrid) {
        const entries = window.PHOTO_ALBUM || [];
        photoGrid.innerHTML = entries.length
            ? entries.map(photoCard).join('')
            : '<p style="color:rgba(20,20,20,0.5);">Photos will appear here as each leg runs.</p>';
    }

    const videoGrid = document.getElementById('video-gallery-grid');
    if (videoGrid) {
        const entries = window.VIDEO_GALLERY || [];
        videoGrid.innerHTML = entries.length
            ? entries.map(videoCard).join('')
            : '<p style="color:rgba(20,20,20,0.5);">Videos will appear here once the Tour\'s YouTube channel is live.</p>';
    }
})();
