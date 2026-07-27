import { NextResponse, type NextRequest } from "next/server";
import {
  requestRedirectDecision,
  redirectLookupUrl,
  redirectTargetUrl,
  shouldSkipRedirectLookup,
} from "@/lib/redirects/legacyRedirects";

type RedirectResolveResponse = {
  data: {
    target_url: string;
    status_code: number;
  };
};

const apiBaseUrl = process.env.INTERNAL_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_BASE_URL;

function redirectResponse(
  request: NextRequest,
  decision: ReturnType<typeof requestRedirectDecision>,
) {
  if (!decision) {
    return NextResponse.next();
  }

  if (decision.kind === "resolved") {
    return NextResponse.redirect(
      redirectTargetUrl(request.url, decision.targetUrl),
      decision.status,
    );
  }

  const target = new URL(request.url);
  target.pathname = decision.pathname;

  return NextResponse.redirect(target, decision.status);
}

export async function proxy(request: NextRequest) {
  const fallbackDecision = requestRedirectDecision(request.nextUrl.pathname);

  // The retired WordPress posts page is a fixed one-hop redirect. Generic
  // trailing-slash canonicalization is only a fallback after the DB resolver,
  // because migrated WordPress source URLs conventionally end in a slash.
  if (fallbackDecision?.kind === "canonical" && fallbackDecision.status === 301) {
    return redirectResponse(request, fallbackDecision);
  }

  if (!apiBaseUrl || shouldSkipRedirectLookup(request.nextUrl.pathname)) {
    return redirectResponse(request, fallbackDecision);
  }

  try {
    const response = await fetch(redirectLookupUrl(apiBaseUrl, request.url), {
      headers: { Accept: "application/json" },
      next: { revalidate: 0 },
    });

    if (response.status === 404 || !response.ok) {
      return redirectResponse(request, fallbackDecision);
    }

    const redirect = (await response.json()) as RedirectResolveResponse;

    return redirectResponse(
      request,
      requestRedirectDecision(request.nextUrl.pathname, redirect.data),
    );
  } catch {
    return redirectResponse(request, fallbackDecision);
  }
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
