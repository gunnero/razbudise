import type { Metadata } from "next";
import { TypeArchivePage } from "@/components/TypeArchivePage";
import { archiveMetadata } from "@/lib/seo/pagination";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type ColumnsPageProps = {
  searchParams: Promise<{ page?: string }>;
};

const columnsArchive = {
  title: "Колумни",
  description: "Авторски ставови, размислувања и коментари од Разбуди се.",
  path: "/kolumni",
};

export async function generateMetadata({ searchParams }: ColumnsPageProps): Promise<Metadata> {
  const { page } = await searchParams;

  return archiveMetadata({ ...columnsArchive, page });
}

export default async function ColumnsPage({ searchParams }: ColumnsPageProps) {
  const { page } = await searchParams;

  return (
    <TypeArchivePage
      archiveLabel="Колумни"
      categoryArchiveHref="/category/kolumni"
      description="Авторски текстови и лични перспективи за култура, секојдневие, градски живот и јавни теми."
      emptyDescription="Кога ќе има објавени колумни, тие ќе се појават тука."
      emptyTitle="Нема објавени колумни."
      heading="Колумни"
      kicker="Став"
      latestHeading="Последни колумни"
      page={page}
      path="/kolumni"
      type="column"
    />
  );
}
