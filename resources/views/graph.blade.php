@extends('commonplace::layouts.app', [
    'title' => 'Knowledge Graph — Commonplace',
    'description' => 'Interactive knowledge graph visualisation.',
])

@push('head')
<script src="https://d3js.org/d3.v7.min.js" defer></script>
@endpush

@section('content')
<section class="cp-graph" aria-labelledby="cp-graph-heading"
    data-graph-endpoint="{{ route('commonplace.graph.api') }}"
    data-note-base="{{ url(config('commonplace.routes.prefix', 'commonplace')) }}">

    <div class="cp-section-header">
        <div>
            <nav class="cp-breadcrumbs" aria-label="Vault path">
                <a href="{{ route('commonplace.index') }}">Notes</a>
                <span aria-hidden="true">/</span>
                <span>Graph</span>
            </nav>
            <h1 id="cp-graph-heading">Knowledge Graph</h1>
        </div>
        <div class="cp-graph-controls">
            <label class="cp-graph-filter">
                <span>Folder:</span>
                <select data-cp-graph-folder>
                    <option value="">All</option>
                </select>
            </label>
            <label class="cp-graph-filter">
                <span>Tag:</span>
                <select data-cp-graph-tag>
                    <option value="">All</option>
                </select>
            </label>
            <button type="button" class="cp-graph-reset" data-cp-graph-reset>Reset</button>
        </div>
    </div>

    <div class="cp-graph-container">
        <div class="cp-graph-canvas" data-cp-graph-canvas></div>
        <div class="cp-graph-info" data-cp-graph-info>
            <p class="cp-graph-hint">Click a node to see details</p>
        </div>
    </div>

    <div class="cp-graph-legend">
        <span class="cp-graph-legend-item">
            <span class="cp-graph-legend-dot" style="background: var(--commonplace-graph-journal)"></span> journal
        </span>
        <span class="cp-graph-legend-item">
            <span class="cp-graph-legend-dot" style="background: var(--commonplace-graph-projects)"></span> projects
        </span>
        <span class="cp-graph-legend-item">
            <span class="cp-graph-legend-dot" style="background: var(--commonplace-graph-people)"></span> people
        </span>
        <span class="cp-graph-legend-item">
            <span class="cp-graph-legend-dot" style="background: var(--commonplace-graph-other)"></span> other
        </span>
    </div>
</section>
@endsection

@push('scripts')
<script src="{{ route('commonplace.asset.js') }}" defer></script>
@endpush
