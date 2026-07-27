"use client";

import Image from "next/image";
import Link from "next/link";
import { useState } from "react";

import { HeaderDateTime } from "@/components/HeaderDateTime";
import { ApiRequestError, loginCustomer } from "@/lib/api/client";

type LoginState =
  | { kind: "idle"; message: string | null }
  | { kind: "submitting"; message: string }
  | { kind: "error"; message: string };

function loginErrorMessage(error: unknown): string {
  if (error instanceof ApiRequestError) {
    if (error.status === 422 || error.status === 401) {
      return "Е-поштата или лозинката не се точни.";
    }

    if (error.status === 403) {
      return "Оваа сметка нема активен рекламен dashboard. Dashboard се отвора по одобрена уплата.";
    }
  }

  return "Најавата не успеа. Проверете ги податоците и обидете се повторно.";
}

function CustomerAdHeader() {
  return (
    <header className="site-header" aria-label="Разбуди се реклами">
      <div className="site-header-inner">
        <div className="site-header-edition">
          <HeaderDateTime />
          <span className="site-header-description">Рекламен dashboard за клиенти</span>
        </div>
        <Link className="brand site-masthead" href="/">
          <Image
            alt="Разбуди се"
            className="brand-logo"
            height={624}
            priority
            src="/brand/razbudise-logo.png"
            width={2386}
          />
        </Link>
      </div>
    </header>
  );
}

export default function CustomerAdLoginPage() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [state, setState] = useState<LoginState>({ kind: "idle", message: null });

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();

    if (state.kind === "submitting") {
      return;
    }

    setState({ kind: "submitting", message: "Се најавуваме..." });

    try {
      await loginCustomer(email.trim(), password);
      window.location.href = "/reklami/dashboard";
    } catch (error) {
      setState({ kind: "error", message: loginErrorMessage(error) });
    }
  }

  return (
    <main className="site-shell">
      <CustomerAdHeader />

      <section className="page-heading page-heading-wide legal-page-heading">
        <p className="kicker">Рекламирање</p>
        <h1>Најава за рекламен dashboard</h1>
        <p>
          Dashboard се отвора за клиенти по одобрена уплата. Тука може да ги следите активните закупи,
          профактурите, резултатите и барањата за продолжување.
        </p>
      </section>

      <form className="legal-page" onSubmit={handleSubmit}>
        <article>
          <h2>Најава</h2>
          <p>Користете ја е-поштата со која е одобрена рекламната кампања.</p>
          <div className="search-form search-form-expanded">
            <label>
              Е-пошта
              <input
                autoComplete="email"
                name="email"
                onChange={(event) => setEmail(event.target.value)}
                required
                type="email"
                value={email}
              />
            </label>
            <label>
              Лозинка
              <input
                autoComplete="current-password"
                name="password"
                onChange={(event) => setPassword(event.target.value)}
                required
                type="password"
                value={password}
              />
            </label>
            <button disabled={state.kind === "submitting"} type="submit">
              {state.kind === "submitting" ? "Се најавува..." : "Најави се"}
            </button>
          </div>
          {state.message ? <p className="ad-price-list-note">{state.message}</p> : null}
        </article>
      </form>

      <section className="ad-price-list" aria-label="Помош за рекламен dashboard">
        <article className="ad-price-list-group">
          <h2>Немате пристап?</h2>
          <p className="ad-price-list-note">
            Ако барањето е ново, dashboard ќе биде достапен по проверка на кампањата и рачна потврда на уплатата.
          </p>
          <Link className="legal-page-action" href="/reklami/zakazi">
            Закажи рекламна кампања
          </Link>
        </article>
      </section>
    </main>
  );
}
