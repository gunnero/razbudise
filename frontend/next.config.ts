import type { NextConfig } from "next";
import path from "node:path";
import { fileURLToPath } from "node:url";

const frontendRoot = path.dirname(fileURLToPath(import.meta.url));

const nextConfig: NextConfig = {
  skipTrailingSlashRedirect: true,
  async redirects() {
    return [
      {
        source: "/blog",
        destination: "/",
        statusCode: 301,
      },
      {
        source: "/sitemap_index.xml",
        destination: "/sitemap.xml",
        statusCode: 301,
      },
      {
        source: "/impresium",
        destination: "/impresum",
        permanent: true,
      },
    ];
  },
  turbopack: {
    root: frontendRoot,
  },
};

export default nextConfig;
