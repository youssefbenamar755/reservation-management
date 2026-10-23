import type {
    ApprovedOrderDocument,
    GeneratedItinerarySource,
    ItineraryAttachmentContext,
} from '@/types/document-delivery';

export function currentItineraryDocument(
    document: ApprovedOrderDocument,
    attachment?: ItineraryAttachmentContext,
): boolean {
    if (document.source !== 'submission_itinerary') return true;
    return Boolean(
        attachment?.available &&
        attachment.source &&
        document.submission_id === attachment.source.submission_id &&
        document.source_version === attachment.source.source_version,
    );
}

export function readAddedItinerary(
    value: unknown,
    source: GeneratedItinerarySource,
): ApprovedOrderDocument {
    const document = value as ApprovedOrderDocument | null;
    if (
        !document ||
        typeof document.id !== 'string' ||
        !document.id ||
        typeof document.name !== 'string' ||
        !document.name ||
        !Number.isSafeInteger(document.size) ||
        document.size < 1 ||
        !Number.isSafeInteger(document.version) ||
        document.version < 1 ||
        !/^[a-f0-9]{64}$/i.test(document.sha256) ||
        document.approved !== true ||
        document.source !== 'submission_itinerary' ||
        document.submission_id !== source.submission_id ||
        document.source_version !== source.source_version
    ) {
        throw new Error('The saved PDF did not match the selected itinerary.');
    }
    return document;
}

export function attachmentLimitError(
    files: Array<{ size: number }>,
    limits: {
        max_files: number;
        max_file_bytes: number;
        max_total_bytes: number;
    },
): string | null {
    if (files.length > limits.max_files)
        return `Select up to ${limits.max_files} PDFs for this email.`;
    if (files.some((file) => file.size > limits.max_file_bytes))
        return 'A selected PDF exceeds the per-file attachment limit.';
    if (
        files.reduce((sum, file) => sum + file.size, 0) > limits.max_total_bytes
    )
        return 'The selected PDFs exceed the total attachment size limit. Remove a PDF before adding another.';
    return null;
}
