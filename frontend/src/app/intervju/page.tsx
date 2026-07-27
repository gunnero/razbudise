import type { Metadata } from "next";
import { TypeArchivePage } from "@/components/TypeArchivePage";
import { archiveMetadata } from "@/lib/seo/pagination";

export const dynamic = "force-dynamic";
export const revalidate = 0;

type InterviewsPageProps = {
  searchParams: Promise<{ page?: string }>;
};

const interviewsArchive = {
  title: "Интервју",
  description: "Разговори со автори, креативци, професионалци и локални гласови.",
  path: "/intervju",
};

export async function generateMetadata({ searchParams }: InterviewsPageProps): Promise<Metadata> {
  const { page } = await searchParams;

  return archiveMetadata({ ...interviewsArchive, page });
}

export default async function InterviewsPage({ searchParams }: InterviewsPageProps) {
  const { page } = await searchParams;

  return (
    <TypeArchivePage
      archiveLabel="Интервју"
      categoryArchiveHref="/category/intervju"
      description="Профили и разговори со луѓе кои носат идеи, култура, бизнис и локална енергија."
      emptyDescription="Кога ќе има објавени интервјуа, тие ќе се појават тука."
      emptyTitle="Нема објавени интервјуа."
      heading="Интервју"
      kicker="Разговори"
      latestHeading="Последни интервјуа"
      page={page}
      path="/intervju"
      type="interview"
    />
  );
}
