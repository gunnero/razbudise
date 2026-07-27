"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Grid3X3, Home, Menu, Search, Users } from "lucide-react";
import type { NavigationItem } from "@/lib/navigation/siteNavigation";

type MobileAppChromeProps = {
  sections: NavigationItem[];
};

function isActivePath(pathname: string, href: string): boolean {
  if (href === "/") {
    return pathname === "/";
  }

  return pathname === href || pathname.startsWith(`${href}/`);
}

function MobilePanelLink({ item }: { item: NavigationItem }) {
  return (
    <Link
      href={item.href}
      rel={item.opensInNewTab ? "noopener noreferrer" : undefined}
      target={item.opensInNewTab ? "_blank" : undefined}
    >
      {item.label}
    </Link>
  );
}

function MobileCategoryPanel({ sections }: { sections: NavigationItem[] }) {
  return (
    <details className="mobile-app-panel mobile-app-category-panel" id="mobile-app-rubrics">
      <summary>
        <Grid3X3 aria-hidden="true" size={20} strokeWidth={1.9} />
        <span>Рубрики</span>
      </summary>
      <nav aria-label="Мобилни рубрики">
        {sections.map((section) => (
          <div className="mobile-app-category-group" key={`${section.href}-${section.label}`}>
            <MobilePanelLink item={section} />
            {section.children?.length ? (
              <div>
                {section.children.map((child) => (
                  <MobilePanelLink item={child} key={`${child.href}-${child.label}`} />
                ))}
              </div>
            ) : null}
          </div>
        ))}
      </nav>
    </details>
  );
}

function MobileMenuPanel({ sections }: { sections: NavigationItem[] }) {
  const utilityLinks: NavigationItem[] = [
    { href: "/search", label: "Пребарување", opensInNewTab: false },
    { href: "/tim", label: "Тим", opensInNewTab: false },
    { href: "/za-nas", label: "За нас", opensInNewTab: false },
    { href: "/kontakt", label: "Контакт", opensInNewTab: false },
    { href: "/cenovnik-za-reklamiranje", label: "Рекламирање", opensInNewTab: false },
  ];

  return (
    <details className="mobile-app-panel mobile-app-menu-panel" id="mobile-app-menu">
      <summary>
        <Menu aria-hidden="true" size={21} strokeWidth={1.9} />
        <span>Мени</span>
      </summary>
      <nav aria-label="Мобилно мени">
        <div className="mobile-app-menu-primary">
          {utilityLinks.map((item) => (
            <MobilePanelLink item={item} key={`${item.href}-${item.label}`} />
          ))}
        </div>
        <div className="mobile-app-menu-secondary">
          {sections.slice(0, 6).map((section) => (
            <MobilePanelLink item={section} key={`menu-${section.href}-${section.label}`} />
          ))}
        </div>
      </nav>
    </details>
  );
}

export function MobileAppChrome({ sections }: MobileAppChromeProps) {
  const pathname = usePathname();
  const hideBottomNavigation = pathname.startsWith("/reklami/zakazi");

  const bottomItems = [
    { href: "/", icon: Home, label: "Почетна" },
    { href: "#mobile-app-rubrics", icon: Grid3X3, label: "Рубрики", panel: "rubrics" },
    { href: "/search", icon: Search, label: "Пребарај" },
    { href: "/tim", icon: Users, label: "Тим" },
    { href: "#mobile-app-menu", icon: Menu, label: "Мени", panel: "menu" },
  ] as const;

  return (
    <div className="mobile-app-chrome" data-bottom-navigation={hideBottomNavigation ? "hidden" : "visible"}>
      <div className="mobile-app-panels">
        <MobileCategoryPanel sections={sections} />
        <MobileMenuPanel sections={sections} />
      </div>

      {!hideBottomNavigation ? (
        <nav className="mobile-bottom-nav" aria-label="Главна мобилна навигација">
          {bottomItems.map((item) => {
            const Icon = item.icon;
            const isPanel = "panel" in item;
            const isActive = !isPanel && isActivePath(pathname, item.href);

            return (
              <a
                aria-current={isActive ? "page" : undefined}
                aria-label={item.label}
                className={isActive ? "is-active" : undefined}
                href={item.href}
                key={item.label}
              >
                <Icon aria-hidden="true" size={20} strokeWidth={1.9} />
                <span>{item.label}</span>
              </a>
            );
          })}
        </nav>
      ) : null}
    </div>
  );
}
