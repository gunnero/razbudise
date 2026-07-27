import {
  adProductCreativeTypeOptions as adProductCreativeTypeOptionsBase,
  adProductDurationOptions as adProductDurationOptionsBase,
  adProductPriceForSelection as adProductPriceForSelectionBase,
  adProductSelectionForChange as adProductSelectionForChangeBase,
  bookingRequestErrorMessage as bookingRequestErrorMessageBase,
  bookingEndDate as bookingEndDateBase,
  creativeDimensionError as creativeDimensionErrorBase,
  formatDenars as formatDenarsBase,
  localDateInputValue as localDateInputValueBase,
  packageLabel as packageLabelBase,
} from "./bookingPresentation.mjs";
import type { AdProduct } from "@/lib/api/client";

export type AdProductPriceSelection = {
  creative_type: "image" | "html" | "script";
  duration_weeks: 1 | 2 | 4;
  is_political_or_sensitive?: boolean;
};

export const formatDenars = formatDenarsBase as (amount: number) => string;

export const packageLabel = packageLabelBase as (durationWeeks: number) => string;

export const bookingEndDate = bookingEndDateBase as (
  startDate: string,
  durationWeeks: number,
) => string;

export const creativeDimensionError = creativeDimensionErrorBase as (input: {
  actualHeight: number;
  actualWidth: number;
  requiredHeight: number;
  requiredWidth: number;
}) => string;

export const localDateInputValue = localDateInputValueBase as (date?: Date, timeZone?: string) => string;

export const adProductDurationOptions = adProductDurationOptionsBase as (
  product?: Pick<AdProduct, "prices">,
) => Array<1 | 2 | 4>;

export const adProductCreativeTypeOptions = adProductCreativeTypeOptionsBase as (
  product?: Pick<AdProduct, "prices">,
  durationWeeks?: 1 | 2 | 4 | null,
) => Array<"image" | "html" | "script">;

export const adProductSelectionForChange = adProductSelectionForChangeBase as (
  product: Pick<AdProduct, "prices"> | undefined,
  selection?: Partial<AdProductPriceSelection>,
  changedField?: "creative" | "duration" | "product",
) => AdProductPriceSelection;

export const adProductPriceForSelection = adProductPriceForSelectionBase as (
  product: Pick<AdProduct, "prices"> | undefined,
  selection: AdProductPriceSelection,
) => number | null;

export const bookingRequestErrorMessage = bookingRequestErrorMessageBase as (
  error: unknown,
  fallback?: string,
) => string;
