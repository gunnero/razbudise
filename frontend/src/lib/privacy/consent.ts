export const privacyConsentStorageKey = "razbudise-privacy-consent";
export const privacyConsentChangedEvent = "razbudise:privacy-consent-changed";

export type PrivacyConsent = {
  analytics: boolean;
  externalMedia: boolean;
  version: 1;
};

export const necessaryOnlyConsent: PrivacyConsent = {
  analytics: false,
  externalMedia: false,
  version: 1,
};

export function readPrivacyConsent(): PrivacyConsent | null {
  if (typeof window === "undefined") return null;

  try {
    const value = JSON.parse(window.localStorage.getItem(privacyConsentStorageKey) ?? "null") as Partial<PrivacyConsent> | null;
    if (!value || value.version !== 1) return null;

    return {
      analytics: value.analytics === true,
      externalMedia: value.externalMedia === true,
      version: 1,
    };
  } catch {
    return null;
  }
}

export function storePrivacyConsent(consent: PrivacyConsent): void {
  window.localStorage.setItem(privacyConsentStorageKey, JSON.stringify(consent));
  window.dispatchEvent(new CustomEvent(privacyConsentChangedEvent, { detail: consent }));
}
