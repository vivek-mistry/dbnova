/** Whether the browser runs on macOS or iOS, where shortcuts use ⌘ instead of Ctrl. */
export function isApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const platform =
        (navigator as Navigator & { userAgentData?: { platform?: string } })
            .userAgentData?.platform ?? navigator.platform;

    return /mac|iphone|ipad|ipod/i.test(platform);
}

/** The modifier label for shortcuts: "⌘" on Apple platforms, "Ctrl" elsewhere. */
export function modifierKey(): string {
    return isApplePlatform() ? '⌘' : 'Ctrl';
}
