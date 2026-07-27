import type { MetadataRoute } from "next";
import { indexingDisabled } from "@/lib/config/runtime";
import { buildRobotsRules, siteUrl } from "@/lib/seo/discovery";

export default function robots(): MetadataRoute.Robots {
  return buildRobotsRules(siteUrl(), indexingDisabled());
}
