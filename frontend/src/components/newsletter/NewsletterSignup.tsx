"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";

type SignupState = "idle" | "submitting" | "success" | "error";

export function NewsletterSignup() {
  const [state, setState] = useState<SignupState>("idle");
  const [message, setMessage] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setState("submitting");
    setMessage(null);

    const form = event.currentTarget;
    const formData = new FormData(form);

    const response = await fetch("/api/newsletter/subscribers", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        email: formData.get("email"),
        source: "footer",
      }),
    }).catch(() => null);

    if (!response?.ok) {
      setState("error");
      setMessage("Проверете ја е-поштата и обидете се повторно.");

      return;
    }

    form.reset();
    setState("success");
    setMessage("Успешно се пријавивте за новости.");
  }

  return (
    <section className="newsletter-signup" aria-label="Newsletter">
      <div>
        <p className="kicker">Билтен</p>
        <h2>Разбуди се во твоето сандаче</h2>
        <p>Најнови приказни, интервјуа и настани директно на е-пошта.</p>
      </div>
      <form onSubmit={onSubmit}>
        <label className="visually-hidden" htmlFor="footer-newsletter-email">Е-пошта</label>
        <input id="footer-newsletter-email" name="email" placeholder="email@example.com" required type="email" />
        <button disabled={state === "submitting"} type="submit">
          {state === "submitting" ? "Се праќа..." : "Пријави се"}
        </button>
      </form>
      <p className="form-privacy-notice">Со пријавувањето се согласувате да добивате билтен од Разбуди се. Можете да се одјавите во секое време. <Link href="/politika-za-privatnost">Политика за приватност</Link>.</p>
      {message ? <p className={`newsletter-message newsletter-message-${state}`}>{message}</p> : null}
    </section>
  );
}
