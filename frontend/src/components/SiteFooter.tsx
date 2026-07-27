import Image from "next/image";
import Link from "next/link";
import { NewsletterSignup } from "@/components/newsletter/NewsletterSignup";
import { SiteContainer } from "@/components/layout/SiteContainer";
import { getMenu, getPublicSiteSettings } from "@/lib/api/client";
import { footerNavigationItems } from "@/lib/navigation/siteNavigation";
import { publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";
import { PrivacySettingsButton } from "@/components/privacy/PrivacyPreferences";

const pomeloYoutubeUrl = "https://www.youtube.com/@pomelotv.official";
const primaryFooterHrefs = ["/", "/za-nas", "/kontakt", "/tim"];

function SocialIcon({ name }: { name: "facebook" | "instagram" | "youtube" }) {
  if (name === "instagram") {
    return (
      <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
        <rect height="17" rx="4.5" stroke="currentColor" strokeWidth="1.9" width="17" x="3.5" y="3.5" />
        <circle cx="12" cy="12" r="4" stroke="currentColor" strokeWidth="1.9" />
        <circle cx="17.6" cy="6.5" fill="currentColor" r="1" />
      </svg>
    );
  }

  if (name === "youtube") {
    return (
      <svg aria-hidden="true" fill="none" viewBox="0 0 24 24">
        <path d="M21 8.1a3 3 0 0 0-2.1-2.1C17 5.5 12 5.5 12 5.5S7 5.5 5.1 6A3 3 0 0 0 3 8.1 31 31 0 0 0 2.7 12 31 31 0 0 0 3 15.9 3 3 0 0 0 5.1 18c1.9.5 6.9.5 6.9.5s5 0 6.9-.5a3 3 0 0 0 2.1-2.1 31 31 0 0 0 .3-3.9 31 31 0 0 0-.3-3.9Z" stroke="currentColor" strokeLinejoin="round" strokeWidth="1.9" />
        <path d="m10 9 5 3-5 3V9Z" fill="currentColor" />
      </svg>
    );
  }

  return (
    <svg aria-hidden="true" fill="currentColor" viewBox="0 0 24 24">
      <path d="M13.7 21v-8h2.8l.4-3.2h-3.2v-2c0-.9.3-1.6 1.7-1.6h1.7V3.3c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.4H7.6V13h2.8v8h3.3Z" />
    </svg>
  );
}

export async function SiteFooter() {
  const [menu, publicSettings] = await Promise.all([
    getMenu("footer").catch(() => null),
    getPublicSiteSettings(),
  ]);
  const items = footerNavigationItems(menu);
  const primaryItems = primaryFooterHrefs.map((href) =>
    items.find((item) => item.href === href) ?? footerNavigationItems(null).find((item) => item.href === href),
  ).filter((item): item is NonNullable<typeof item> => Boolean(item));
  const settings = publicSiteSettingsDefaults(publicSettings);

  return (
    <footer className="site-footer" aria-label="Footer">
      <SiteContainer className="site-footer-inner">
        <div className="footer-primary">
          <div className="footer-brand">
            <Link className="footer-brand-mark" href="/">{settings.siteName}</Link>
            <p>{settings.defaultMetaDescription}</p>
            <div className="footer-brand-actions">
              <div className="footer-social-links" aria-label="Социјални мрежи">
                {[
                  ["facebook", "Facebook", "https://www.facebook.com/profile.php?id=100093113592171"],
                  ["instagram", "Instagram", "https://www.instagram.com/razbudise2023/"],
                  ["youtube", "YouTube", pomeloYoutubeUrl],
                ].map(([name, label, href]) => (
                  <a aria-label={label} data-tooltip={label} href={href} key={name} rel="noopener noreferrer" target="_blank">
                    <SocialIcon name={name as "facebook" | "instagram" | "youtube"} />
                  </a>
                ))}
              </div>
              <Link className="footer-advertise-link" href="/cenovnik-za-reklamiranje">Рекламирајте се →</Link>
            </div>
            <a className="footer-youtube-channel" href={pomeloYoutubeUrl} rel="noopener noreferrer" target="_blank">
              <Image alt="" aria-hidden="true" height={44} src="/brand/pomelo-tv.webp" width={44} />
              <span><small>Нашиот YouTube канал</small><strong>Pomelo TV</strong></span>
              <em>Отвори канал →</em>
            </a>
          </div>
          <div className="footer-newsletter-panel"><NewsletterSignup /></div>
        </div>
        {primaryItems.length > 0 ? (
          <nav className="footer-nav" aria-label="Корисни врски">
              {primaryItems.map((item) => (
                <Link
                  href={item.href}
                  key={`${item.href}-${item.label}`}
                  rel={item.opensInNewTab ? "noopener noreferrer" : undefined}
                  target={item.opensInNewTab ? "_blank" : undefined}
                >
                  {item.label}
                </Link>
              ))}
          </nav>
        ) : null}
        <div className="footer-legal">
          <span>© 2026 Разбуди се</span>
          <div>
            <Link href="/impresum">Импресум</Link>
            <Link href="/uslovi-za-koristenje">Услови за користење</Link>
            <Link href="/politika-za-privatnost">Политика за приватност</Link>
            <PrivacySettingsButton />
          </div>
        </div>
      </SiteContainer>
    </footer>
  );
}
