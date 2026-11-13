/**
 * Links from mod and modpack pages are third-party text, so only plain web
 * links are allowed. Anything else (javascript:, data:, vbscript:, relative
 * paths) returns null and is shown as text instead of a link.
 */
export const safeExternalUrl = (raw: string | null | undefined): string | null => {
    if (!raw) return null;
    const value = raw.trim();
    try {
        const url = new URL(value);
        return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
    } catch {
        return null;
    }
};
