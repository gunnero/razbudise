"use client";

import Link from "next/link";

type ErrorPageProps = {
  error: Error & { digest?: string };
  reset: () => void;
};

export default function ErrorPage({ error, reset }: ErrorPageProps) {
  return (
    <main className="site-shell">
      <section className="status-panel status-panel-editorial" role="alert">
        <p className="kicker">Грешка</p>
        <h1>Не можеме да ја прикажеме страницата во моментов.</h1>
        <p>Освежи ја страницата или продолжи кон почетната додека ја проверуваме состојбата.</p>
        {error.digest ? <small>Код: {error.digest}</small> : null}
        <div className="status-actions">
          <button type="button" onClick={reset}>
            Обиди се повторно
          </button>
          <Link href="/">Почетна</Link>
        </div>
      </section>
    </main>
  );
}
