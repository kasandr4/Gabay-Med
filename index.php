<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Oriental Mindoro Central District Hospital — Patient Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,500;1,9..144,600&family=Inter:wght@400;500;600;700;800&display=swap"
    rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/style.css" />
  <noscript>
    <style>
      body {
        opacity: 1 !important;
      }
    </style>
  </noscript>
</head>

<body>
  <header>
    <nav class="nav wrap" style="padding-left: 0; padding-right: 0">
      <a
        href="#"
        class="logo-slot"
        aria-label="Oriental Mindoro Central District Hospital home">
        <img src="logo-icon.png" alt="" style="height: 36px; width: 36px" />
        <span class="logo-text">GabayMed</span>
      </a>

      <div class="nav-links">
        <a href="#services">Services</a>
        <a href="#how-it-works">How it works</a>
        <a href="#features">What you can do</a>
      </div>

      <div class="nav-actions">
        <a href="login.php" class="btn btn-ghost">Log in</a>
        <a href="register.php" class="btn btn-primary">Get started</a>
      </div>

      <button
        class="mobile-toggle"
        type="button"
        aria-label="Open menu"
        aria-expanded="false"
        aria-controls="mobile-menu">
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round">
          <line x1="3" y1="6" x2="21" y2="6" />
          <line x1="3" y1="12" x2="21" y2="12" />
          <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
      </button>
    </nav>

    <div class="mobile-menu" id="mobile-menu">
      <a href="#services">Services</a>
      <a href="#how-it-works">How it works</a>
      <a href="#features">What you can do</a>
      <div class="mobile-menu-actions">
        <a href="login.php" class="btn btn-ghost">Log in</a>
        <a href="register.php" class="btn btn-primary">Get started</a>
      </div>
    </div>
  </header>

  <main>
    <!-- ============ HERO ============ -->
    <section class="hero">
      <div class="wrap hero-grid">
        <div>
          <span class="eyebrow">Oriental Mindoro Central District Hospital</span>
          <h1>Your healthcare,<br /><em>made simple.</em></h1>
          <p class="hero-sub">
            Register once, get matched to the right department, and book a
            doctor in minutes. Your prescription follows you all the way to
            the pharmacy counter — right here in Papandayan, Pinamalayan.
          </p>

          <div class="hero-actions">
            <a href="register.php" class="btn btn-primary btn-large">Create your account</a>
            <a href="login.php" class="btn btn-ghost btn-large">I already have one</a>
          </div>

          <div class="trust-row">
            <div class="trust-item">
              <span class="trust-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round">
                  <path
                    d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.5 4.04 3 5.5l7 7Z" />
                </svg>
              </span>
              <div>
                <span class="trust-num">5</span><span class="trust-label">Departments</span>
              </div>
            </div>
            <div class="trust-item">
              <span class="trust-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round">
                  <rect x="3" y="4" width="18" height="18" rx="2" />
                  <path d="M3 9h18M8 2v4M16 2v4" />
                </svg>
              </span>
              <div>
                <span class="trust-num">1hr</span><span class="trust-label">Booking slots</span>
              </div>
            </div>
            <div class="trust-item">
              <span class="trust-icon">
                <svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  stroke-linecap="round"
                  stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10" />
                  <path d="M12 7v5l3 3" />
                </svg>
              </span>
              <div>
                <span class="trust-num">24/7</span><span class="trust-label">Account access</span>
              </div>
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
                  <span>Status</span><span class="status-chip">Confirmed</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ============ TRUST STRIP ============ -->
    <section class="wrap">
      <div class="trust-strip">
        <div class="trust-strip-item">
          <span class="trust-strip-icon"><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path
                d="M12 2 4 5v6c0 5 3.4 8.7 8 11 4.6-2.3 8-6 8-11V5l-8-3Z" />
              <path d="M9 12l2 2 4-4" />
            </svg></span>
          <span>Licensed doctors</span>
        </div>
        <div class="trust-strip-item">
          <span class="trust-strip-icon"><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="10" rx="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg></span>
          <span>Secure medical records</span>
        </div>
        <div class="trust-strip-item">
          <span class="trust-strip-icon"><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M9 11l3 3L22 4" />
              <path
                d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
            </svg></span>
          <span>Privacy protected</span>
        </div>
        <div class="trust-strip-item">
          <span class="trust-strip-icon"><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <circle cx="12" cy="8" r="4" />
              <path d="M4 21v-1a8 8 0 0 1 16 0v1" />
            </svg></span>
          <span>Community healthcare</span>
        </div>
        <div class="trust-strip-item">
          <span class="trust-strip-icon"><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
            </svg></span>
          <span>Emergency ready</span>
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
          <div class="step-tag">01</div>
          <h3>Create your account</h3>
          <p>
            Sign up with your details, verify with a one-time code, and you're
            in — ready to book anytime.
          </p>
          <div class="step-connector"></div>
        </div>
        <div class="step">
          <div class="step-tag">02</div>
          <h3>Describe &amp; book a slot</h3>
          <p>
            Tell us your symptoms, get a department recommendation, then pick
            a doctor and a one-hour slot that fits your week.
          </p>
          <div class="step-connector"></div>
        </div>
        <div class="step">
          <div class="step-tag">03</div>
          <h3>Check in &amp; get treated</h3>
          <p>
            Check in at the front desk, see your doctor, and your prescription
            is sent straight to the pharmacy.
          </p>
        </div>
      </div>
    </section>

    <!-- ============ SERVICES ============ -->
    <section class="section wrap" id="services">
      <div class="wrap">
        <div class="section-eyebrow">Departments</div>
        <h2>Care across the departments you need.</h2>
        <p class="section-sub">
          Every department runs on the same booking system, so switching
          between them never means starting over.
        </p>
      </div>

      <div class="services-grid">
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path
                d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.5 4.04 3 5.5l7 7Z" />
            </svg>
          </div>
          <h4>Internal Medicine</h4>
          <p>General checkups and ongoing care for adults.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <circle cx="12" cy="8" r="4" />
              <path d="M4 21v-1a8 8 0 0 1 16 0v1" />
            </svg>
          </div>
          <h4>Pediatrics</h4>
          <p>Checkups, vaccines, and care built for kids.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path
                d="M12 21s-7-4.6-9.5-9C.9 8.3 2 4.8 5.3 4a4.6 4.6 0 0 1 4.7 1.7A4.6 4.6 0 0 1 14.7 4C18 4.8 19.1 8.3 17.5 12 15 16.4 12 21 12 21Z" />
            </svg>
          </div>
          <h4>OB-Gynecology</h4>
          <p>Prenatal visits and women's health services.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M9 3h6l1 4H8l1-4Z" />
              <path d="M8 7h8v10a4 4 0 0 1-4 4 4 4 0 0 1-4-4V7Z" />
            </svg>
          </div>
          <h4>Dental</h4>
          <p>Cleanings, fillings, and routine dental care.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path
                d="M9 2v5.5L4 17a2 2 0 0 0 1.8 3h12.4a2 2 0 0 0 1.8-3L15 7.5V2" />
              <path d="M9 2h6M9 12h6" />
            </svg>
          </div>
          <h4>Laboratory</h4>
          <p>Blood work and diagnostic testing on-site.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <circle cx="12" cy="12" r="9" />
              <path d="M12 7v5l4 2" />
            </svg>
          </div>
          <h4>Radiology</h4>
          <p>X-rays and imaging with fast result turnaround.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
            </svg>
          </div>
          <h4>Emergency</h4>
          <p>Urgent care, staffed and ready around the clock.</p>
          <a href="#" class="service-link">Learn more →</a>
        </div>
        <div class="service-card">
          <div class="service-icon">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path
                d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.5 4.04 3 5.5l7 7Z" />
            </svg>
          </div>
          <h4>Pharmacy</h4>
          <p>Prescriptions sent digitally, ready for pickup.</p>
          <a href="#" class="service-link">Learn more →</a>
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
                stroke-linejoin="round">
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
                stroke-linejoin="round">
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
                stroke-linejoin="round">
                <path d="M9 11l3 3L22 4" />
                <path
                  d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
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
                stroke-linejoin="round">
                <path
                  d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.5 4.04 3 5.5l7 7Z" />
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
      <div class="cta-card">
        <div
          class="section-eyebrow"
          style="
              justify-content: center;
              display: flex;
              background: rgba(255, 255, 255, 0.16);
              color: #fff;
            ">
          Ready when you are
        </div>
        <h2>Your next visit starts with one account.</h2>
        <p class="section-sub">Registration takes about two minutes.</p>
        <div class="cta-actions">
          <a href="register.php" class="btn btn-primary btn-large">Create your account</a>
          <a href="login.php" class="btn btn-ghost btn-large">Log in</a>
        </div>
      </div>
    </section>
  </main>

  <footer>
    <div class="wrap">
      <div class="footer-grid">
        <div class="footer-brand">
          <span style="display: flex; align-items: center; gap: 10px">
            <img
              src="logo-icon.png"
              alt=""
              style="height: 28px; width: 28px" />
            <span class="logo-text">OMCDH</span>
          </span>
          <p>
            Oriental Mindoro Central District Hospital's patient system,
            connecting registration, booking, and pharmacy in one secure
            account.
          </p>
          <div class="emergency-pill" style="margin-top: 16px">
            <svg
              viewBox="0 0 24 24"
              width="16"
              height="16"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round">
              <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
            </svg>
            Emergency: (043) 284-1234
          </div>
        </div>

        <div class="footer-col">
          <h5>Quick links</h5>
          <a href="#how-it-works">How it works</a>
          <a href="#services">Departments</a>
          <a href="#features">Features</a>
          <a href="register.php">Create account</a>
        </div>

        <div class="footer-col">
          <h5>Departments</h5>
          <a href="#services">Internal Medicine</a>
          <a href="#services">Pediatrics</a>
          <a href="#services">Dental</a>
          <a href="#services">Pharmacy</a>
        </div>

        <div class="footer-col">
          <h5>Contact</h5>
          <p>Papandayan, Pinamalayan, Oriental Mindoro</p>
          <p>Mon–Sat, 7:00 AM – 7:00 PM</p>
          <a href="mailto:hello@omcdh.gov.ph">hello@omcdh.gov.ph</a>
        </div>
      </div>

      <div class="footer-row">
        <span>&copy; 2026 Oriental Mindoro Central District Hospital. Built for
          better patient care.</span>
        <div class="footer-links">
          <a href="#">Privacy policy</a>
          <a href="#">Terms</a>
          <a href="#">Contact</a>
        </div>
      </div>
    </div>
  </footer>

  <script src="assets/js/page-transitions.js"></script>
  <script src="assets/js/script.js" defer></script>
</body>

</html>