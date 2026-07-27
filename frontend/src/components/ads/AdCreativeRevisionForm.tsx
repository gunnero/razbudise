"use client";

import { useState } from "react";

import type { CustomerAdBookingItem, CustomerAdCreativeRevisionResponse } from "@/lib/api/client";
import { submitCustomerAdCreativeRevision, uploadCustomerAdCreative } from "@/lib/api/client";

type AdCreativeRevisionFormProps = {
  item: CustomerAdBookingItem;
  onSubmitted: (response: CustomerAdCreativeRevisionResponse) => void;
};

type RevisionState =
  | { kind: "idle"; message: string | null }
  | { kind: "submitting"; message: string }
  | { kind: "success"; message: string }
  | { kind: "error"; message: string };

const creativeTypeLabels: Record<"image" | "html" | "script", string> = {
  html: "HTML",
  image: "Слика",
  script: "Скрипта",
};

function revisionErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message) {
    return error.message;
  }

  return "Не можеме да ја испратиме измената на креативот. Обидете се повторно.";
}

export function AdCreativeRevisionForm({ item, onSubmitted }: AdCreativeRevisionFormProps) {
  const [creativeType, setCreativeType] = useState<"image" | "html" | "script">(item.creative_type);
  const [clickUrl, setClickUrl] = useState("");
  const [width, setWidth] = useState(item.width);
  const [height, setHeight] = useState(item.height);
  const [html, setHtml] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [state, setState] = useState<RevisionState>({ kind: "idle", message: null });
  const hasPendingRevision = item.revisions.some((revision) => revision.status === "pending_review");
  const disabled = hasPendingRevision || state.kind === "submitting";

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();

    if (disabled) {
      return;
    }

    if (creativeType === "image" && !file) {
      setState({ kind: "error", message: "Изберете слика за новиот креатив." });
      return;
    }

    if (creativeType !== "image" && html.trim() === "") {
      setState({ kind: "error", message: "Внесете HTML или script код за новиот креатив." });
      return;
    }

    setState({ kind: "submitting", message: "Ја испраќаме измената на проверка..." });

    try {
      const uploaded = creativeType === "image" && file
        ? await uploadCustomerAdCreative(file, `${item.zone_name ?? item.zone_key ?? "Реклама"} креатив`)
        : null;
      const response = await submitCustomerAdCreativeRevision(item.id, {
        click_url: clickUrl.trim() || null,
        creative_type: creativeType,
        height: uploaded?.data.height ?? height,
        html: creativeType === "image" ? null : html.trim(),
        image_id: creativeType === "image" ? uploaded?.data.id ?? null : null,
        width: uploaded?.data.width ?? width,
      });

      setState({
        kind: "success",
        message: "Измената е испратена на проверка. Живиот креатив не се заменува додека администратор не ја одобри.",
      });
      setFile(null);
      setHtml("");
      onSubmitted(response);
    } catch (error) {
      setState({ kind: "error", message: revisionErrorMessage(error) });
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h3>Испрати измена на креатив</h3>
      <p>
        HTML и script креативите влегуваат во техничка проверка. Живиот креатив не се заменува додека измената не
        биде одобрена.
      </p>
      {hasPendingRevision ? (
        <p className="ad-price-list-note">Веќе има испратена измена што чека проверка за оваа позиција.</p>
      ) : null}
      <div className="search-form search-form-expanded">
        <label>
          Тип
          <select
            disabled={disabled}
            value={creativeType}
            onChange={(event) => setCreativeType(event.target.value as "image" | "html" | "script")}
          >
            {(["image", "html", "script"] as const).map((type) => (
              <option key={type} value={type}>
                {creativeTypeLabels[type]}
              </option>
            ))}
          </select>
        </label>
        <label>
          Click URL
          <input
            disabled={disabled}
            onChange={(event) => setClickUrl(event.target.value)}
            placeholder="https://example.com"
            type="url"
            value={clickUrl}
          />
        </label>
        <label>
          Ширина
          <input
            disabled={disabled}
            min="1"
            onChange={(event) => setWidth(Number(event.target.value))}
            type="number"
            value={width || ""}
          />
        </label>
        <label>
          Висина
          <input
            disabled={disabled}
            min="1"
            onChange={(event) => setHeight(Number(event.target.value))}
            type="number"
            value={height || ""}
          />
        </label>
      </div>
      {creativeType === "image" ? (
        <div className="search-form search-form-expanded">
          <label>
            Слика
            <input
              accept="image/*"
              disabled={disabled}
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              type="file"
            />
          </label>
        </div>
      ) : (
        <div className="search-form search-form-expanded">
          <label>
            HTML / script код
            <textarea disabled={disabled} onChange={(event) => setHtml(event.target.value)} value={html} />
          </label>
        </div>
      )}
      <button className="legal-page-action" disabled={disabled} type="submit">
        {state.kind === "submitting" ? "Се испраќа..." : "Испрати измена"}
      </button>
      {state.message ? <p className="ad-price-list-note">{state.message}</p> : null}
    </form>
  );
}
