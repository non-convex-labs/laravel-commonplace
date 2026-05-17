<?php

declare(strict_types=1);

namespace NonConvexLabs\Commonplace\Contracts;

/**
 * Convenience composite of {@see VectorStorage} and {@see VectorSearch}.
 *
 * The package's bundled drivers (InPhpCosine, Pgvector, Null) all implement
 * this composite because they own both the row-local storage column and the
 * search path. Future external-service drivers (Qdrant, Pinecone, Chroma)
 * should implement only {@see VectorSearch} and let a separate
 * {@see VectorStorage} binding manage the row schema — this keeps the
 * abstraction honest without forcing remote-store drivers to stub schema or
 * parse methods that have no meaning for them.
 *
 * Existing call sites can keep depending on this composite for backward
 * compatibility; new code should prefer the narrower contract it actually
 * needs.
 */
interface VectorSearchDriver extends VectorSearch, VectorStorage {}
