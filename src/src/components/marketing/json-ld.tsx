/**
 * A `<script type="application/ld+json">` block, escaped for inline embedding.
 *
 * The `<` escape is not decoration: a `</script>` sequence anywhere inside the
 * serialized data would close this element early and let the rest of the payload
 * be parsed as markup. Nothing here is user-supplied today, but the data will
 * eventually include operator-edited site content, and the escape is the reason
 * that will be safe rather than a thing to remember.
 */
export function JsonLd({ data }: { data: Record<string, unknown> }) {
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{
        __html: JSON.stringify(data).replace(/</g, "\\u003c"),
      }}
    />
  );
}
