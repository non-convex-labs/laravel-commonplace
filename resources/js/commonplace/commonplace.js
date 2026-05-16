/* ==========================================================================
   Commonplace — knowledge graph renderer.
   Self-contained vanilla JS that progressively enhances the graph page.
   Requires D3 v7 to be loaded on the page (the graph view <script> tag does
   this). Reads configuration from data-* attributes on the .cp-graph root.
   ========================================================================== */

(function () {
    'use strict';

    function init() {
        var root = document.querySelector('.cp-graph');
        if (!root) {
            return;
        }
        if (typeof d3 === 'undefined') {
            // D3 not yet available; retry on next animation frame.
            window.requestAnimationFrame(init);
            return;
        }

        var endpoint = root.dataset.graphEndpoint;
        var noteBase = root.dataset.noteBase || '';
        var canvas = root.querySelector('[data-cp-graph-canvas]');
        var info = root.querySelector('[data-cp-graph-info]');
        var folderSelect = root.querySelector('[data-cp-graph-folder]');
        var tagSelect = root.querySelector('[data-cp-graph-tag]');
        var resetBtn = root.querySelector('[data-cp-graph-reset]');

        var state = {
            data: null,
            simulation: null,
            folderFilter: '',
            tagFilter: '',
        };

        function folderColor(folder) {
            var styles = getComputedStyle(document.documentElement);
            var palette = {
                journal: styles.getPropertyValue('--commonplace-graph-journal').trim(),
                projects: styles.getPropertyValue('--commonplace-graph-projects').trim(),
                people: styles.getPropertyValue('--commonplace-graph-people').trim(),
            };
            return palette[folder] || styles.getPropertyValue('--commonplace-graph-other').trim();
        }

        function getFilteredData() {
            var nodes = state.data.nodes;
            if (state.folderFilter) {
                nodes = nodes.filter(function (n) { return n.folder === state.folderFilter; });
            }
            if (state.tagFilter) {
                nodes = nodes.filter(function (n) { return n.tags.indexOf(state.tagFilter) !== -1; });
            }
            var ids = new Set(nodes.map(function (n) { return n.id; }));
            var edges = state.data.edges
                .filter(function (e) { return ids.has(e.source) && ids.has(e.target); })
                .map(function (e) { return { source: e.source, target: e.target }; });
            return { nodes: nodes.slice(), edges: edges };
        }

        function populateFilters() {
            var folders = new Set();
            var tags = new Set();
            state.data.nodes.forEach(function (n) {
                if (n.folder) folders.add(n.folder);
                n.tags.forEach(function (t) { tags.add(t); });
            });
            [].slice.call(folders).sort().forEach(function (f) {
                var opt = document.createElement('option');
                opt.value = f;
                opt.textContent = f;
                folderSelect.appendChild(opt);
            });
            [].slice.call(tags).sort().forEach(function (t) {
                var opt = document.createElement('option');
                opt.value = t;
                opt.textContent = t;
                tagSelect.appendChild(opt);
            });
        }

        function showNodeInfo(d) {
            if (!info) return;
            var tagsHtml = d.tags.length
                ? d.tags.map(function (t) { return '<span class="cp-tag"></span>'; }).join(' ')
                : '<em>No tags</em>';

            info.innerHTML = '';
            var h3 = document.createElement('h3');
            var a = document.createElement('a');
            a.href = noteBase + '/' + d.id;
            a.textContent = d.title;
            h3.appendChild(a);
            info.appendChild(h3);

            var p = document.createElement('p');
            p.className = 'cp-graph-info-path';
            p.textContent = d.id;
            info.appendChild(p);

            if (d.tags.length) {
                var ul = document.createElement('ul');
                ul.className = 'cp-tag-list';
                ul.setAttribute('role', 'list');
                d.tags.forEach(function (t) {
                    var li = document.createElement('li');
                    li.className = 'cp-tag';
                    li.textContent = t;
                    ul.appendChild(li);
                });
                info.appendChild(ul);
            }
        }

        function dragBehavior(simulation) {
            return d3.drag()
                .on('start', function (event, d) {
                    if (!event.active) simulation.alphaTarget(0.3).restart();
                    d.fx = d.x;
                    d.fy = d.y;
                })
                .on('drag', function (event, d) {
                    d.fx = event.x;
                    d.fy = event.y;
                })
                .on('end', function (event, d) {
                    if (!event.active) simulation.alphaTarget(0);
                    d.fx = null;
                    d.fy = null;
                });
        }

        function render() {
            if (!canvas || !state.data) return;
            canvas.innerHTML = '';

            var data = getFilteredData();
            if (data.nodes.length === 0) return;

            var rect = canvas.getBoundingClientRect();
            var width = rect.width || 800;
            var height = rect.height || 600;

            var svg = d3.select(canvas)
                .append('svg')
                .attr('width', width)
                .attr('height', height)
                .attr('viewBox', [0, 0, width, height]);

            var g = svg.append('g');
            var zoom = d3.zoom()
                .scaleExtent([0.1, 4])
                .on('zoom', function (event) { g.attr('transform', event.transform); });
            svg.call(zoom);

            var linkCounts = {};
            data.nodes.forEach(function (n) { linkCounts[n.id] = 0; });
            data.edges.forEach(function (e) {
                var s = typeof e.source === 'object' ? e.source.id : e.source;
                var t = typeof e.target === 'object' ? e.target.id : e.target;
                if (linkCounts[s] !== undefined) linkCounts[s]++;
                if (linkCounts[t] !== undefined) linkCounts[t]++;
            });

            var radiusScale = d3.scaleSqrt()
                .domain([0, d3.max(Object.values(linkCounts)) || 1])
                .range([4, 16]);

            if (state.simulation) state.simulation.stop();

            state.simulation = d3.forceSimulation(data.nodes)
                .force('link', d3.forceLink(data.edges).id(function (d) { return d.id; }).distance(80))
                .force('charge', d3.forceManyBody().strength(-120))
                .force('center', d3.forceCenter(width / 2, height / 2))
                .force('collision', d3.forceCollide().radius(function (d) {
                    return radiusScale(linkCounts[d.id] || 0) + 2;
                }));

            var link = g.append('g').attr('class', 'cp-graph-links')
                .selectAll('line').data(data.edges).join('line');

            var node = g.append('g').attr('class', 'cp-graph-nodes')
                .selectAll('circle').data(data.nodes).join('circle')
                .attr('r', function (d) { return radiusScale(linkCounts[d.id] || 0); })
                .attr('fill', function (d) { return folderColor(d.folder); })
                .call(dragBehavior(state.simulation));

            node.append('title').text(function (d) { return d.title; });

            node.on('click', function (event, d) {
                showNodeInfo(d);
                d3.selectAll('.cp-graph-nodes circle').classed('cp-graph-node-selected', false);
                d3.select(this).classed('cp-graph-node-selected', true);
            });

            state.simulation.on('tick', function () {
                link
                    .attr('x1', function (d) { return d.source.x; })
                    .attr('y1', function (d) { return d.source.y; })
                    .attr('x2', function (d) { return d.target.x; })
                    .attr('y2', function (d) { return d.target.y; });
                node
                    .attr('cx', function (d) { return d.x; })
                    .attr('cy', function (d) { return d.y; });
            });
        }

        function load() {
            fetch(endpoint, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    state.data = data;
                    populateFilters();
                    render();
                })
                .catch(function (err) {
                    console.error('Commonplace: failed to load graph', err);
                });
        }

        if (folderSelect) {
            folderSelect.addEventListener('change', function (e) {
                state.folderFilter = e.target.value;
                render();
            });
        }
        if (tagSelect) {
            tagSelect.addEventListener('change', function (e) {
                state.tagFilter = e.target.value;
                render();
            });
        }
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                state.folderFilter = '';
                state.tagFilter = '';
                if (folderSelect) folderSelect.value = '';
                if (tagSelect) tagSelect.value = '';
                render();
            });
        }

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
