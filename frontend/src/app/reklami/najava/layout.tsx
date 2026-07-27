import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Најава за рекламен dashboard",
  description: "Најава за клиентскиот рекламен dashboard на Разбуди се.",
  alternates: {
    canonical: "/reklami/najava",
  },
  robots: {
    index: false,
    follow: false,
    googleBot: {
      index: false,
      follow: false,
    },
  },
};

export default function CustomerAdLoginLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return children;
}
