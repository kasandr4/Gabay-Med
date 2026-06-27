<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gabay-Med — Your Guide to Better Care</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300..700&family=Inter:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="style.css" />
  </head>
  <body>
    <header>
      <nav class="nav wrap" style="padding-left: 0; padding-right: 0">
        <a href="#" class="logo-slot" aria-label="Gabay-Med home">
          <img src="logo-icon.png" alt="" style="height: 36px; width: 36px" />
          <span class="logo-text">Gabay&#8209;Med</span>
        </a>

        <div class="nav-links">
          <a href="#how-it-works">How it works</a>
          <a href="#features">What you can do</a>
          <a href="#">For clinics</a>
        </div>

        <div class="nav-actions">
          <a href="login.php" class="btn btn-ghost">Log in</a>
          <a href="register.php" class="btn btn-primary">Get started</a>
        </div>
      </nav>
    </header>

    <main>
      <!-- ============ HERO ============ -->
      <section class="hero wrap">
        <div class="hero-grid">
          <div>
            <span class="eyebrow">Patient portal &amp; clinic system</span>
            <h1>Your visit, <em>guided</em><br />from booking to medicine.</h1>
            <p class="hero-sub">
              Gabay-Med lets you register once, book the right department in
              minutes, and track your prescription all the way to the pharmacy
              counter.
            </p>

            <div class="hero-actions">
              <a href="register.php" class="btn btn-primary btn-large"
                >Create your account</a
              >
              <a href="login.php" class="btn btn-ghost btn-large"
                >I already have one</a
              >
            </div>

            <div class="trust-row">
              <div class="trust-item">
                <span class="trust-num">5</span>
                <span class="trust-label">Departments&nbsp;covered</span>
              </div>
              <div class="trust-item">
                <span class="trust-num">1hr</span>
                <span class="trust-label">Booking slots</span>
              </div>
              <div class="trust-item">
                <span class="trust-num">24/7</span>
                <span class="trust-label">Account access</span>
              </div>
            </div>
          </div>

          <div class="hero-visual">
            <div class="ticket-stack">
              <div class="ticket-behind"></div>
              <div class="ticket">
                <div class="ticket-top">
                  <div>
                    <div class="ticket-label">Today's queue</div>
                    <div class="ticket-dept">Internal Medicine</div>
                  </div>
                  <div class="ticket-num">B14</div>
                </div>
                <div class="ticket-divider"></div>
                <div class="ticket-rows">
                  <div class="ticket-row">
                    <span>Doctor</span><span>Dr. R. Santos</span>
                  </div>
                  <div class="ticket-row">
                    <span>Slot</span><span>2:00 – 3:00 PM</span>
                  </div>
                  <div class="ticket-row">
                    <span>Status</span
                    ><span class="status-chip">Confirmed</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ HOW IT WORKS ============ -->
      <section class="section wrap" id="how-it-works">
        <div class="section-head">
          <div class="section-eyebrow">The patient journey</div>
          <h2>Three steps, start to finish.</h2>
          <p class="section-sub">
            No paper forms, no guessing which department you need — the system
            walks you through each one.
          </p>
        </div>

        <div class="steps">
          <div class="step">
            <div class="step-tag">01 — Register</div>
            <h3>Create your account</h3>
            <p>
              Sign up with your details, verify with a one-time code, and you're
              in — ready to book anytime.
            </p>
            <div class="step-connector"></div>
          </div>
          <div class="step">
            <div class="step-tag">02 — Book</div>
            <h3>Describe &amp; book a slot</h3>
            <p>
              Tell us your symptoms, get a department recommendation, then pick
              a doctor and a one-hour slot that fits your week.
            </p>
            <div class="step-connector"></div>
          </div>
          <div class="step">
            <div class="step-tag">03 — Get seen</div>
            <h3>Check in &amp; get treated</h3>
            <p>
              Check in at the front desk, see your doctor, and your prescription
              is sent straight to the pharmacy.
            </p>
          </div>
        </div>
      </section>

      <!-- ============ FEATURES ============ -->
      <section class="section wrap" id="features">
        <div class="features-band">
          <div>
            <div class="section-eyebrow" style="color: var(--sage)">
              Built for the whole visit
            </div>
            <h2>One account.<br />Every part of your care.</h2>
            <p class="section-sub">
              From the moment you register to the moment you pick up your
              medicine, everything stays connected to your file.
            </p>
          </div>

          <div class="feature-list">
            <div class="feature-item">
              <div class="feature-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <circle cx="12" cy="8" r="4" />
                  <path d="M4 21v-1a8 8 0 0 1 16 0v1" />
                </svg>
              </div>
              <div>
                <h4>Personal dashboard</h4>
                <p>
                  See your upcoming and past appointments in one place, anytime.
                </p>
              </div>
            </div>

            <div class="feature-item">
              <div class="feature-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <rect x="3" y="4" width="18" height="18" rx="2" />
                  <path d="M3 9h18M8 2v4M16 2v4" />
                </svg>
              </div>
              <div>
                <h4>Weekly slot calendar</h4>
                <p>
                  Pick the exact hour that works for you, by doctor and
                  department.
                </p>
              </div>
            </div>

            <div class="feature-item">
              <div class="feature-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="M9 11l3 3L22 4" />
                  <path
                    d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"
                  />
                </svg>
              </div>
              <div>
                <h4>Real-time check-in</h4>
                <p>
                  Front-desk check-in with a grace period, so a late arrival
                  doesn't always mean losing your slot.
                </p>
              </div>
            </div>

            <div class="feature-item">
              <div class="feature-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.5 4.04 3 5.5l7 7Z"
                  />
                </svg>
              </div>
              <div>
                <h4>Prescription tracking</h4>
                <p>
                  Your prescription moves straight from the doctor to the
                  pharmacy queue — no paper to lose.
                </p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ============ FINAL CTA ============ -->
      <section class="cta-section wrap">
        <div
          class="section-eyebrow"
          style="justify-content: center; display: flex"
        >
          Ready when you are
        </div>
        <h2>Your next visit starts with one account.</h2>
        <p class="section-sub">Registration takes about two minutes.</p>
        <div class="cta-actions">
          <a href="register.php" class="btn btn-primary btn-large"
            >Create your account</a
          >
          <a href="login.php" class="btn btn-ghost btn-large">Log in</a>
        </div>
      </section>
    </main>

    <footer>
      <div class="wrap footer-row">
        <span style="display: flex; align-items: center; gap: 10px">
          <img src="logo-icon.png" alt="" style="height: 20px; width: 20px" />
          &copy; 2026 Gabay-Med. Built for better patient care.
        </span>
        <div class="footer-links">
          <a href="#how-it-works">How it works</a>
          <a href="#features">Features</a>
          <a href="#">Contact</a>
        </div>
      </div>
    </footer>
  </body>
</html>
