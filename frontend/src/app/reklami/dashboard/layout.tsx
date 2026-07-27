import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Рекламен dashboard",
  description: "Приватен клиентски рекламен dashboard на Разбуди се.",
  alternates: {
    canonical: "/reklami/dashboard",
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

export default function CustomerAdDashboardLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return children;
}
