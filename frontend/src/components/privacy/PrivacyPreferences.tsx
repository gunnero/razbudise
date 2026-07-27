"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  necessaryOnlyConsent,
  readPrivacyConsent,
  storePrivacyConsent,
  type PrivacyConsent,
} from "@/lib/privacy/consent";

export function PrivacyPreferences() {
  const [consent, setConsent] = useState<PrivacyConsent>(necessaryOnlyConsent);
  const [mounted, setMounted] = useState(false);
  const [open, setOpen] = useState(false);
  const [hasChoice, setHasChoice] = useState(true);

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => {
      const stored = readPrivacyConsent();
      setConsent(stored ?? necessaryOnlyConsent);
      setHasChoice(stored !== null);
      setOpen(stored === null);
      setMounted(true);
    });

    const openSettings = () => setOpen(true);
    window.addEventListener("razbudise:open-privacy-settings", openSettings);
    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener("razbudise:open-privacy-settings", openSettings);
    };
  }, []);

  function save(next: PrivacyConsent) {
    storePrivacyConsent(next);
    setConsent(next);
    setHasChoice(true);
    setOpen(false);
  }

  if (!mounted || (!open && hasChoice)) return null;

  return (
    <section aria-label="Поставки за приватност" aria-live="polite" className="privacy-consent" role="dialog">
      <div className="privacy-consent-inner">
        <div>
          <p className="kicker">ПРИВАТНОСТ</p>
          <h2>Ваш избор за аналитика и надворешна содржина</h2>
          <p>
            Неопходните функции работат без избор. Google Analytics и вградената содржина од други платформи
            се вчитуваат само со ваша дозвола. <Link href="/politika-za-privatnost">Прочитајте ја политиката</Link>.
          </p>
        </div>
        <fieldset>
          <legend>Опционални поставки</legend>
          <label><input checked disabled type="checkbox" /> Неопходни функции</label>
          <label><input checked={consent.analytics} onChange={(event) => setConsent({ ...consent, analytics: event.target.checked })} type="checkbox" /> Аналитика</label>
          <label><input checked={consent.externalMedia} onChange={(event) => setConsent({ ...consent, externalMedia: event.target.checked })} type="checkbox" /> Надворешна вградена содржина</label>
        </fieldset>
        <div className="privacy-consent-actions">
          <button onClick={() => save(necessaryOnlyConsent)} type="button">Само неопходни</button>
          <button onClick={() => save(consent)} type="button">Зачувај избор</button>
          <button className="privacy-consent-primary" onClick={() => save({ analytics: true, externalMedia: true, version: 1 })} type="button">Дозволи сè</button>
        </div>
      </div>
    </section>
  );
}

export function PrivacySettingsButton() {
  return <button className="footer-privacy-settings" onClick={() => window.dispatchEvent(new Event("razbudise:open-privacy-settings"))} type="button">Поставки за колачиња</button>;
}
