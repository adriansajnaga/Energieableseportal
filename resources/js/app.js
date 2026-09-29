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
