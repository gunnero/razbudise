import Image from "next/image";
import Link from "next/link";
import { HeaderDateTime } from "@/components/HeaderDateTime";
import { HeaderSearch } from "@/components/HeaderSearch";
import { MobileAppChrome } from "@/components/MobileAppChrome";
import { ThemeToggle } from "@/components/ThemeToggle";
import { SiteContainer } from "@/components/layout/SiteContainer";
import { getMenu, getPublicSiteSettings } from "@/lib/api/client";
import { headerNavigationItems, type NavigationItem } from "@/lib/navigation/siteNavigation";
import { publicSiteSettingsDefaults } from "@/lib/settings/siteSettings";

function NavigationLink({
  className,
  item,
}: {
  className?: string;
  item: NavigationItem;
}) {
  return (
    <Link
      className={className}
      href={item.href}
      rel={item.opensInNewTab ? "noopener noreferrer" : undefined}
      target={item.opensInNewTab ? "_blank" : undefined}
    >
      {item.label}
    </Link>
  );
}

function DesktopNavigationItem({ item }: { item: NavigationItem }) {
  if (!item.children?.length) {
    return <NavigationLink item={item} />;
  }

  return (
    <div className="section-nav-group">
      <NavigationLink className="section-nav-link" item={item} />
      <div className="section-nav-submenu" role="menu">
        <NavigationLink className="section-nav-all" item={{ ...item, label: `Сите: ${item.label}` }} />
        {item.children.map((child) => (
          <NavigationLink className="section-nav-child" item={child} key={`${child.href}-${child.label}`} />
        ))}
      </div>
    </div>
  );
}

function MobileNavigationItem({ item }: { item: NavigationItem }) {
  if (!item.children?.length) {
    return <NavigationLink item={item} />;
  }

  return (
    <details className="mobile-section-group">
      <summary>{item.label}</summary>
      <div className="mobile-section-group-links">
        <NavigationLink className="mobile-section-all" item={{ ...item, label: `Сите: ${item.label}` }} />
        {item.children.map((child) => (
          <NavigationLink item={child} key={`${child.href}-${child.label}`} />
        ))}
      </div>
    </details>
  );
}

export type SiteHeaderVariant = "editorial" | "commercial" | "minimal";

type SiteHeaderProps = {
  variant?: SiteHeaderVariant;
  showThemeToggle?: boolean;
  showSearch?: boolean;
  showPrimaryNavigation?: boolean;
};

export async function SiteHeader({
  variant = "editorial",
  showThemeToggle = true,
  showSearch = true,
  showPrimaryNavigation = true,
}: SiteHeaderProps = {}) {
  const [menu, publicSettings] = await Promise.all([
    getMenu("header").catch(() => null),
    getPublicSiteSettings(),
  ]);
  const sections = headerNavigationItems(menu);
  const settings = publicSiteSettingsDefaults(publicSettings);

  return (
    <header className={`site-header site-header-${variant}`} aria-label="Primary">
      <MobileAppChrome sections={sections} />
      <SiteContainer className="site-header-inner">
        <div className="site-header-edition">
          <HeaderDateTime />
          <span className="site-header-description">{settings.defaultMetaDescription}</span>
          <div className="site-header-actions">
            {showThemeToggle ? <ThemeToggle /> : null}
            {showSearch ? <HeaderSearch /> : null}
          </div>
        </div>
        <Link className="brand site-masthead" href="/">
          <Image
            alt={settings.siteName}
            className="brand-logo"
            height={624}
            priority
            src="/brand/razbudise-logo.png"
            width={2386}
          />
        </Link>
        {showPrimaryNavigation ? <div className="section-nav-scroll">
          <nav className="section-nav" aria-label="Главни рубрики">
            {sections.map((section) => (
              <DesktopNavigationItem item={section} key={`${section.href}-${section.label}`} />
            ))}
          </nav>
        </div> : null}
        {showPrimaryNavigation ? <details className="mobile-section-menu">
          <summary className="mobile-section-menu-summary">
            <span>Мени</span>
          </summary>
          <nav className="mobile-section-grid" aria-label="Рубрики">
            {sections.map((section) => (
              <MobileNavigationItem item={section} key={`mobile-${section.href}-${section.label}`} />
            ))}
          </nav>
        </details> : null}
      </SiteContainer>
    </header>
  );
}
