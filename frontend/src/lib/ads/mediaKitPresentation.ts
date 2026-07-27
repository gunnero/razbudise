import type { AdProduct } from "@/lib/api/client";
import { bookingUrl as bookingUrlBase, publicAdProducts as publicAdProductsBase } from "./mediaKitPresentation.mjs";

export type PublicAdProduct = {
  id: string;
  category: "home" | "article" | "archives" | "mobile" | "other";
  name: string;
  description: string;
  device: string;
  bookingZoneKey: string;
  internalZoneKeys: string[];
  sizes: number[][];
  prices: AdProduct["prices"];
  startingPrice: number | null;
  availabilityUrl?: string;
};

export const publicAdProducts = publicAdProductsBase as (products: AdProduct[]) => PublicAdProduct[];
export const bookingUrl = bookingUrlBase as (zoneKey?: string, durationWeeks?: number) => string;
