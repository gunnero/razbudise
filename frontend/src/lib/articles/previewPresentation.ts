import {
  mobileArticleFlow as mobileArticleFlowBase,
  previewBanner as previewBannerBase,
  previewMetadata as previewMetadataBase,
} from "./previewPresentation.mjs";

export const previewBanner = previewBannerBase as (isPreview: boolean) => {
  label: string;
  message: string;
} | null;

export const previewMetadata = previewMetadataBase as (isPreview: boolean) => {
  robots?: {
    index: false;
    follow: false;
    googleBot: {
      index: false;
      follow: false;
    };
  };
};

export const mobileArticleFlow = mobileArticleFlowBase as (input?: {
  hasFeaturedImage?: boolean;
  hasRelated?: boolean;
}) => string[];
