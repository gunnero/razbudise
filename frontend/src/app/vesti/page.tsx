import type { Metadata } from "next";
import { TypeArchivePage } from "@/components/TypeArchivePage";
import { archiveMetadata } from "@/lib/seo/pagination";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type NewsPageProps = {
  searchParams: Promise<{ page?: string }>;
};

const newsArchive = {
  title: "Вести",
  description: "Најнови вести и актуелни информации од редакцијата на Разбуди се.",
  path: "/vesti",
};

export async function generateMetadata({ searchParams }: NewsPageProps): Promise<Metadata> {
  const { page } = await searchParams;

  return archiveMetadata({ ...newsArchive, page });
}

export default async function NewsPage({ searchParams }: NewsPageProps) {
  const { page } = await searchParams;

  return (
    <TypeArchivePage
      archiveLabel="Вести"
      categoryArchiveHref="/category/vesti"
      description="Брзи, јасни и редакциски проверени информации за темите што ја движат јавноста."
      emptyDescription="Кога редакцијата ќе објави news текстови, тие ќе се појават тука."
      emptyTitle="Нема објавени вести."
      heading="Вести"
      kicker="Актуелно"
      latestHeading="Последни вести"
      page={page}
      path="/vesti"
      type="news"
    />
  );
}
