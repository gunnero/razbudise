import {
  homepageAdZoneAfter as homepageAdZoneAfterBase,
  homepageAdSlotsAfter as homepageAdSlotsAfterBase,
  homepageLeadLayout as homepageLeadLayoutBase,
  homepagePromoSections as homepagePromoSectionsBase,
  homepageSectionGridClassName as homepageSectionGridClassNameBase,
  mobileHomepageOrder as mobileHomepageOrderBase,
  visibleHomepageSections as visibleHomepageSectionsBase,
} from "./homepagePresentation.mjs";
import type { HomepageSection } from "@/lib/api/client";

export const homepageLeadLayout = homepageLeadLayoutBase as (sections: HomepageSection[]) => {
  featured: HomepageSection | null;
  leadArticle: HomepageSection["articles"][number] | null;
  sideArticles: HomepageSection["articles"];
  remainingFeatured: HomepageSection["articles"];
};

export const visibleHomepageSections = visibleHomepageSectionsBase as (
  sections: HomepageSection[],
  featuredKey?: string | null,
  leadPackageArticles?: HomepageSection["articles"],
) => HomepageSection[];

export const homepagePromoSections = homepagePromoSectionsBase as (sections: HomepageSection[]) => HomepageSection[];

export const homepageAdZoneAfter = homepageAdZoneAfterBase as (
  section: Pick<HomepageSection, "ad_zone_after">,
  index: number,
) => string | null;

export const homepageAdSlotsAfter = homepageAdSlotsAfterBase as (
  section: Pick<HomepageSection, "ad_zone_after">,
  index: number,
) => string[];

export const homepageSectionGridClassName = homepageSectionGridClassNameBase as (
  section: Pick<HomepageSection, "layout" | "articles">,
) => string;

export const mobileHomepageOrder = mobileHomepageOrderBase as (sections: HomepageSection[]) => string[];
