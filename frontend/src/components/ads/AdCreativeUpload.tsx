/* eslint-disable @next/next/no-img-element */
"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";

import type { AdCreativeUpload } from "@/lib/api/client";
import { claimCustomerAdCreative, getCustomerAdCreativeMedia, removePublicAdCreative, uploadPublicAdCreative } from "@/lib/api/client";
import { normalizeMediaUrl } from "@/lib/media/mediaUrls";

export type SelectedAdCreative = AdCreativeUpload & { source: "existing" | "upload" };

type Props = {
  allowedSizes: number[][];
  onChange: (creative: SelectedAdCreative | null) => void;
  value: SelectedAdCreative | null;
};

const acceptedTypes = new Set(["image/png", "image/jpeg", "image/webp", "image/gif"]);
const maxBytes = 5 * 1024 * 1024;

function fileSize(bytes: number | null): string {
  if (!bytes) return "—";
  return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
}

function sizeLabel(sizes: number[][]): string {
  return sizes.map(([width, height]) => `${width} × ${height} px`).join(" • ") || "Според избраната позиција";
}

async function imageDimensions(file: File): Promise<[number, number]> {
  const url = URL.createObjectURL(file);
  try {
    return await new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve([image.naturalWidth, image.naturalHeight]);
      image.onerror = () => reject(new Error("Сликата не може да се прочита."));
      image.src = url;
    });
  } finally {
    URL.revokeObjectURL(url);
  }
}

export function AdCreativeUpload({ allowedSizes, onChange, value }: Props) {
  const inputId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [retryFile, setRetryFile] = useState<File | null>(null);
  const [existing, setExisting] = useState<AdCreativeUpload[] | null>(null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [query, setQuery] = useState("");

  useEffect(() => {
    getCustomerAdCreativeMedia().then(setExisting).catch(() => setExisting(null));
  }, []);

  const filteredExisting = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase("mk");
    return (existing ?? []).filter((asset) => !normalized || asset.original_name.toLocaleLowerCase("mk").includes(normalized));
  }, [existing, query]);

  async function upload(file: File): Promise<void> {
    setMessage(null);
    setRetryFile(null);
    if (!acceptedTypes.has(file.type)) {
      setMessage("Дозволени формати се PNG, JPG, JPEG, WEBP и GIF.");
      return;
    }
    if (file.size > maxBytes) {
      setMessage("Сликата не смее да биде поголема од 5 MB.");
      return;
    }

    let width: number;
    let height: number;
    try {
      [width, height] = await imageDimensions(file);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Сликата не може да се прочита.");
      return;
    }
    if (allowedSizes.length && !allowedSizes.some(([allowedWidth, allowedHeight]) => allowedWidth === width && allowedHeight === height)) {
      setMessage(`Сликата е ${width} × ${height} px. Потребна големина: ${sizeLabel(allowedSizes)}.`);
      return;
    }

    setProgress(0);
    try {
      const previousToken = value?.upload_token;
      const response = await uploadPublicAdCreative(file, setProgress);
      onChange({ ...response.data, source: "upload" });
      if (previousToken && previousToken !== response.data.upload_token) {
        void removePublicAdCreative(previousToken).catch(() => undefined);
      }
      setProgress(null);
      setMessage("Сликата е успешно прикачена.");
    } catch (error) {
      setProgress(null);
      setRetryFile(file);
      setMessage(error instanceof Error ? error.message : "Прикачувањето не успеа. Обидете се повторно.");
    }
  }

  async function remove(): Promise<void> {
    if (value?.upload_token) {
      await removePublicAdCreative(value.upload_token).catch(() => undefined);
    }
    onChange(null);
    setMessage("Сликата е отстранета.");
  }

  if (value) {
    return (
      <section className="ad-creative-upload-card" aria-label="Прикачена рекламна слика">
        <img alt={`Преглед на ${value.original_name}`} src={normalizeMediaUrl(value.thumbnail_url ?? value.url) ?? ""} />
        <div><strong>{value.original_name}</strong><span>{value.width} × {value.height} px</span><span>{fileSize(value.size_bytes)}</span></div>
        <div className="ad-creative-upload-actions">
          <button type="button" onClick={() => inputRef.current?.click()}>Замени</button>
          <button type="button" onClick={remove}>Отстрани</button>
        </div>
        <input ref={inputRef} accept=".png,.jpg,.jpeg,.webp,.gif" hidden type="file" onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ""; if (file) upload(file); }} />
        <p aria-live="polite" className="ad-creative-upload-message">{message}</p>
      </section>
    );
  }

  return (
    <section className="ad-creative-upload">
      <div className="ad-creative-upload-heading"><div><span>Прикачи рекламна слика</span><strong>Препорачана големина: {sizeLabel(allowedSizes)}</strong></div><small>PNG • JPG • JPEG • WEBP • GIF · Максимум 5 MB</small></div>
      <div
        className={`ad-creative-dropzone${dragging ? " is-dragging" : ""}`}
        onDragEnter={(event) => { event.preventDefault(); setDragging(true); }}
        onDragOver={(event) => event.preventDefault()}
        onDragLeave={() => setDragging(false)}
        onDrop={(event) => { event.preventDefault(); setDragging(false); const file = event.dataTransfer.files[0]; if (file) upload(file); }}
      >
        <input ref={inputRef} accept=".png,.jpg,.jpeg,.webp,.gif" id={inputId} type="file" onChange={(event) => { const file = event.target.files?.[0]; event.target.value = ""; if (file) upload(file); }} />
        <label htmlFor={inputId}><strong>Повлечете ја сликата тука</strong><span>или кликнете за да изберете датотека</span></label>
        {progress !== null ? <div className="ad-creative-progress" aria-live="polite"><progress max="100" value={progress} /><span>Прикачување {progress}%</span></div> : null}
      </div>
      <p className="ad-creative-later-note">Ако немате подготвен банер, можете да го испратите подоцна.</p>
      {retryFile ? <button className="ad-creative-retry" type="button" onClick={() => upload(retryFile)}>Обиди се повторно</button> : null}
      {existing !== null ? <button className="ad-creative-existing-toggle" type="button" onClick={() => setPickerOpen(!pickerOpen)}>Избери постоечка слика</button> : null}
      {pickerOpen ? <div className="ad-creative-picker"><label>Пребарај слики<input value={query} onChange={(event) => setQuery(event.target.value)} type="search" /></label><div>{filteredExisting.map((asset) => <button key={asset.media_id} type="button" onClick={async () => { if (!asset.media_id) return; try { const claimed = await claimCustomerAdCreative(asset.media_id); onChange({ ...claimed, source: "existing" }); setPickerOpen(false); } catch (error) { setMessage(error instanceof Error ? error.message : "Сликата не може да се избере. Обидете се повторно."); } }}><img alt="" src={normalizeMediaUrl(asset.thumbnail_url ?? asset.url) ?? ""} /><span>{asset.original_name}</span></button>)}</div></div> : null}
      <p aria-live="polite" className="ad-creative-upload-message">{message}</p>
    </section>
  );
}
