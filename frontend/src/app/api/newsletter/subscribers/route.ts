import { NextResponse } from "next/server";

const apiBaseUrl =
  process.env.INTERNAL_API_BASE_URL ??
  process.env.NEXT_PUBLIC_API_BASE_URL ??
  "http://localhost:8001/api";

export async function POST(request: Request) {
  const payload = await request.json().catch(() => null);

  if (!payload || typeof payload !== "object") {
    return NextResponse.json({ message: "Invalid newsletter signup payload." }, { status: 422 });
  }

  const response = await fetch(`${apiBaseUrl}/newsletter/subscribers`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
    cache: "no-store",
  }).catch(() => null);

  if (!response) {
    return NextResponse.json({ message: "Newsletter service is unavailable." }, { status: 503 });
  }

  const data = await response.json().catch(() => ({}));

  return NextResponse.json(data, { status: response.status });
}
