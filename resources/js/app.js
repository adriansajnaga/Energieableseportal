import Chart from 'chart.js/auto';

window.Chart = Chart;

// Diagramm aus JSON-Daten zeichnen; wird per Alpine x-init aufgerufen und bei wire:navigate neu erzeugt.
window.energieChart = (canvas, config) => {
    const dark = document.documentElement.classList.contains('dark');
    const grid = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    const text = dark ? '#a3a3a3' : '#525252';

    Chart.getChart(canvas)?.destroy();

    return new Chart(canvas, {
        ...config,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: text, boxWidth: 12 } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: text } },
                y: { grid: { color: grid }, ticks: { color: text }, beginAtZero: true },
                ...(config.options?.scales ?? {}),
            },
            ...(config.options ?? {}),
        },
    });
};

// Erste Seite eines PDF-Plans auf ein Canvas zeichnen, damit Zähler darauf markiert werden können.
// pdf.js liegt unter public/vendor/pdfjs und wird erst bei Bedarf geladen (Pfade kommen aus Blade, wegen /em).
window.renderPdfPlan = async (canvas, url, lib) => {
    const pdfjs = await import(/* @vite-ignore */ lib.module);
    pdfjs.GlobalWorkerOptions.workerSrc = lib.worker;

    const pdf = await pdfjs.getDocument({ url, standardFontDataUrl: lib.fonts }).promise;
    const page = await pdf.getPage(1);
    const base = page.getViewport({ scale: 1 });
    const width = Math.min(3000, Math.max(1400, canvas.parentElement.clientWidth * (window.devicePixelRatio || 1) * 1.5));
    const viewport = page.getViewport({ scale: width / base.width });

    canvas.width = Math.floor(viewport.width);
    canvas.height = Math.floor(viewport.height);
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
};
