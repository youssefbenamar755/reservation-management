/** Click-to-chat needs the full international number, without formatting. */
export function whatsappUrl(value: unknown): string | null {
    if (
        typeof value === 'number' &&
        (!Number.isSafeInteger(value) || value <= 0)
    ) {
        return null;
    }
    if (typeof value !== 'string' && typeof value !== 'number') return null;

    const phone = String(value).trim();
    if (!/^\+?[\d\s().-]+$/.test(phone)) return null;

    let digits = phone.replace(/\D/g, '');
    if (!phone.startsWith('+') && digits.startsWith('00'))
        digits = digits.slice(2);

    // Never guess a country code or remove zeros within the actual number.
    if (!/^[1-9]\d{6,14}$/.test(digits)) return null;
    return `https://wa.me/${digits}`;
}

export function isPhoneField(field: { key: string; label: string }): boolean {
    const name = `${field.key} ${field.label}`
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
    return /(?:^|[^a-z])(?:phone|telephone|mobile|cell|cellphone|whats\s*app|tel)(?:[^a-z]|$)/.test(
        name,
    );
}
