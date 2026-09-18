{{--
    The tibeb (ጥበብ) divider — the Ethiopian woven-border motif the design system
    names as ETHR's signature element, and the one thing that makes a page
    unmistakably Ethiopian without saying so.

    Drawn with a repeating gradient rather than an image: no extra request, no
    asset to ship to shared hosting, and it scales to any width. Mirrors the
    treatment already used on the tenant login screen in the React application
    (`(auth)/auth-layout-client.tsx`) so the public page and the app a visitor
    lands in after signing in read as one product.

    Purely decorative, so it is hidden from assistive technology.
--}}
<div class="tibeb" role="presentation" aria-hidden="true"></div>
