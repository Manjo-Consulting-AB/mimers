/*
 * Ikonlistan, se issue 282 och [[ADR-0057 Ikoner och typsnitt]] § 1.
 *
 * **Nyckeln är en roll och inte ikonens namn.** Sidorna väljer `task`,
 * `cost` och `document`, aldrig `circle-check`, `coins` eller
 * `file-text`. Byter vi ikon för kostnader ändras en rad här, och ingen sida
 * behöver röras.
 *
 * **Varje post är Lucides nodformat**, `[['path', { d: '…' }]]`, kopierat ur
 * `lucide-static` 1.59.0. Ingen SVG-markup som sträng och ingen
 * `v-html` — `UiIcon` ritar noderna med `h()`. Licensen ligger oförändrad
 * i `LICENSE` bredvid.
 *
 * **Listan är sluten.** En ny ikon kopieras ur Lucide, får ett rollnamn och
 * läggs till här. En ikon som Lucide saknar ritas inte för hand i en
 * komponent, utan blir en fråga i PR:en.
 */
export const icons = {
    // house · lucide-static 1.59.0
    dashboard: [
        ['path', { d: 'M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8' }],
        ['path', { d: 'M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' }],
    ],
    // box · lucide-static 1.59.0
    container: [
        ['path', { d: 'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z' }],
        ['path', { d: 'm3.3 7 8.7 5 8.7-5' }],
        ['path', { d: 'M12 22V12' }],
    ],
    // inbox · lucide-static 1.59.0
    inbox: [
        ['polyline', { points: '22 12 16 12 14 15 10 15 8 12 2 12' }],
        ['path', { d: 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z' }],
    ],
    // list-checks · lucide-static 1.59.0
    tasks: [
        ['path', { d: 'M13 5h8' }],
        ['path', { d: 'M13 12h8' }],
        ['path', { d: 'M13 19h8' }],
        ['path', { d: 'm3 17 2 2 4-4' }],
        ['path', { d: 'm3 7 2 2 4-4' }],
    ],
    // files · lucide-static 1.59.0
    documents: [
        ['path', { d: 'M15 2h-4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8' }],
        ['path', { d: 'M16.706 2.706A2.4 2.4 0 0 0 15 2v5a1 1 0 0 0 1 1h5a2.4 2.4 0 0 0-.706-1.706z' }],
        ['path', { d: 'M5 7a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h8a2 2 0 0 0 1.732-1' }],
    ],
    // history · lucide-static 1.59.0
    history: [
        ['path', { d: 'M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8' }],
        ['path', { d: 'M3 3v5h5' }],
        ['path', { d: 'M12 7v5l4 2' }],
    ],
    // search · lucide-static 1.59.0
    search: [
        ['path', { d: 'm21 21-4.34-4.34' }],
        ['circle', { cx: '11', cy: '11', r: '8' }],
    ],
    // settings · lucide-static 1.59.0
    settings: [
        ['path', { d: 'M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915' }],
        ['circle', { cx: '12', cy: '12', r: '3' }],
    ],
    // bell · lucide-static 1.59.0
    notifications: [
        ['path', { d: 'M10.268 21a2 2 0 0 0 3.464 0' }],
        ['path', { d: 'M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326' }],
    ],
    // star · lucide-static 1.59.0
    favorite: [
        ['path', { d: 'M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z' }],
    ],
    // layout-dashboard · lucide-static 1.59.0
    overview: [
        ['rect', { width: '7', height: '9', x: '3', y: '3', rx: '1' }],
        ['rect', { width: '7', height: '5', x: '14', y: '3', rx: '1' }],
        ['rect', { width: '7', height: '9', x: '14', y: '12', rx: '1' }],
        ['rect', { width: '7', height: '5', x: '3', y: '16', rx: '1' }],
    ],
    // package · lucide-static 1.59.0
    item: [
        ['path', { d: 'M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z' }],
        ['path', { d: 'M12 22V12' }],
        ['polyline', { points: '3.29 7 12 12 20.71 7' }],
        ['path', { d: 'm7.5 4.27 9 5.15' }],
    ],
    // circle-check · lucide-static 1.59.0
    task: [
        ['circle', { cx: '12', cy: '12', r: '10' }],
        ['path', { d: 'm16 9-5.5 5.5L8 12' }],
    ],
    // calendar-clock · lucide-static 1.59.0
    schedule: [
        ['path', { d: 'M16 14v2.2l1.6 1' }],
        ['path', { d: 'M16 2v3' }],
        ['path', { d: 'M21 7.338V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2h2.338' }],
        ['path', { d: 'M3 9h5.859' }],
        ['path', { d: 'M8 2v3' }],
        ['circle', { cx: '16', cy: '16', r: '6' }],
    ],
    // coins · lucide-static 1.59.0
    cost: [
        ['path', { d: 'M13.744 17.736a6 6 0 1 1-7.48-7.48' }],
        ['path', { d: 'M15 6h1v4' }],
        ['path', { d: 'm6.134 14.768.866-.5 2 3.464' }],
        ['circle', { cx: '16', cy: '8', r: '6' }],
    ],
    // file-text · lucide-static 1.59.0
    document: [
        ['path', { d: 'M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z' }],
        ['path', { d: 'M14 2v5a1 1 0 0 0 1 1h5' }],
        ['path', { d: 'M10 9H8' }],
        ['path', { d: 'M16 13H8' }],
        ['path', { d: 'M16 17H8' }],
    ],
    // image · lucide-static 1.59.0
    image: [
        ['rect', { width: '18', height: '18', x: '3', y: '3', rx: '2', ry: '2' }],
        ['circle', { cx: '9', cy: '9', r: '2' }],
        ['path', { d: 'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21' }],
    ],
    // link-2 · lucide-static 1.59.0
    link: [
        ['path', { d: 'M9 17H7A5 5 0 0 1 7 7h2' }],
        ['path', { d: 'M15 7h2a5 5 0 1 1 0 10h-2' }],
        ['line', { x1: '8', x2: '16', y1: '12', y2: '12' }],
    ],
    // handshake · lucide-static 1.59.0
    loan: [
        ['path', { d: 'm11 17 2 2a1 1 0 1 0 3-3' }],
        ['path', { d: 'm14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4' }],
        ['path', { d: 'm21 3 1 11h-2' }],
        ['path', { d: 'M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3' }],
        ['path', { d: 'M3 4h8' }],
    ],
    // tag · lucide-static 1.59.0
    tag: [
        ['path', { d: 'M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z' }],
        ['circle', { cx: '7.5', cy: '7.5', r: '.5', fill: 'currentColor' }],
    ],
    // folder-tree · lucide-static 1.59.0
    category: [
        ['path', { d: 'M20 10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-2.5a1 1 0 0 1-.8-.4l-.9-1.2A1 1 0 0 0 15 3h-2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z' }],
        ['path', { d: 'M20 21a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1h-2.9a1 1 0 0 1-.88-.55l-.42-.85a1 1 0 0 0-.92-.6H13a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z' }],
        ['path', { d: 'M3 5a2 2 0 0 0 2 2h3' }],
        ['path', { d: 'M3 3v13a2 2 0 0 0 2 2h3' }],
    ],
    // network · lucide-static 1.59.0
    map: [
        ['rect', { x: '16', y: '16', width: '6', height: '6', rx: '1' }],
        ['rect', { x: '2', y: '16', width: '6', height: '6', rx: '1' }],
        ['rect', { x: '9', y: '2', width: '6', height: '6', rx: '1' }],
        ['path', { d: 'M5 16v-3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3' }],
        ['path', { d: 'M12 12V8' }],
    ],
    // lightbulb · lucide-static 1.59.0
    tip: [
        ['path', { d: 'M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5' }],
        ['path', { d: 'M9 18h6' }],
        ['path', { d: 'M10 22h4' }],
    ],
    // plus · lucide-static 1.59.0
    add: [
        ['path', { d: 'M5 12h14' }],
        ['path', { d: 'M12 5v14' }],
    ],
    // pencil · lucide-static 1.59.0
    edit: [
        ['path', { d: 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z' }],
        ['path', { d: 'm15 5 4 4' }],
    ],
    // ellipsis · lucide-static 1.59.0
    more: [
        ['circle', { cx: '12', cy: '12', r: '1' }],
        ['circle', { cx: '19', cy: '12', r: '1' }],
        ['circle', { cx: '5', cy: '12', r: '1' }],
    ],
    // trash-2 · lucide-static 1.59.0
    trash: [
        ['path', { d: 'M10 11v6' }],
        ['path', { d: 'M14 11v6' }],
        ['path', { d: 'M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6' }],
        ['path', { d: 'M3 6h18' }],
        ['path', { d: 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2' }],
    ],
    // users · lucide-static 1.59.0
    share: [
        ['path', { d: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' }],
        ['path', { d: 'M16 3.128a4 4 0 0 1 0 7.744' }],
        ['path', { d: 'M22 21v-2a4 4 0 0 0-3-3.87' }],
        ['circle', { cx: '9', cy: '7', r: '4' }],
    ],
    // download · lucide-static 1.59.0
    export: [
        ['path', { d: 'M12 15V3' }],
        ['path', { d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' }],
        ['path', { d: 'm7 10 5 5 5-5' }],
    ],
    // upload · lucide-static 1.59.0
    upload: [
        ['path', { d: 'M12 3v12' }],
        ['path', { d: 'm17 8-5-5-5 5' }],
        ['path', { d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' }],
    ],
    // x · lucide-static 1.59.0
    close: [
        ['path', { d: 'M18 6 6 18' }],
        ['path', { d: 'm6 6 12 12' }],
    ],
    // check · lucide-static 1.59.0
    check: [
        ['path', { d: 'M20 6 9 17l-5-5' }],
    ],
    // chevron-right · lucide-static 1.59.0
    'chevron-right': [
        ['path', { d: 'm9 18 6-6-6-6' }],
    ],
    // chevron-down · lucide-static 1.59.0
    'chevron-down': [
        ['path', { d: 'm6 9 6 6 6-6' }],
    ],
    // arrow-right · lucide-static 1.59.0
    'arrow-right': [
        ['path', { d: 'M5 12h14' }],
        ['path', { d: 'm12 5 7 7-7 7' }],
    ],
    // triangle-alert · lucide-static 1.59.0
    warning: [
        ['path', { d: 'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3' }],
        ['path', { d: 'M12 9v4' }],
        ['path', { d: 'M12 17h.01' }],
    ],
    // clock-alert · lucide-static 1.59.0
    overdue: [
        ['path', { d: 'M12 6v6l4 2' }],
        ['path', { d: 'M20 12v5' }],
        ['path', { d: 'M20 21h.01' }],
        ['path', { d: 'M21.25 8.2A10 10 0 1 0 16 21.16' }],
    ],
};
