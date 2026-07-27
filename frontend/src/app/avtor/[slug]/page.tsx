import { redirect } from "next/navigation";

type LegacyAuthorProfileRedirectProps = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ page?: string }>;
};

export default async function LegacyAuthorProfileRedirectPage({
  params,
  searchParams,
}: LegacyAuthorProfileRedirectProps) {
  const [{ slug }, { page }] = await Promise.all([params, searchParams]);
  const query = page ? `?page=${encodeURIComponent(page)}` : "";

  redirect(`/profil/${slug}${query}`);
}
