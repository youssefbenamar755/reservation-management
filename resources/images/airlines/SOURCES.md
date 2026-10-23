# Airline image sources

These packaged images identify the carrier recorded in a submission. Airline names and logos remain the trademarks of their respective owners; their presence does not imply endorsement, ticket issuance or booking confirmation.

## User-provided reference

`AF.png` was extracted from the user-provided sample itinerary PDF without changing its appearance. Its original dimensions are 108 × 92 pixels, including its white canvas.

The same reference supplied `../itinerary/outbound.png` and `../itinerary/return.png`. These are the two original 24 × 23 pixel plane icons, with their transparency preserved. No customer details or text from the sample document are included in these assets.

## Other carrier logos

The 37 other packaged carrier logos come from Kiwi.com's fixed airline-image CDN:

```text
https://images.kiwi.com/airlines/64/{UPPERCASE_IATA_CODE}.png
```

Example source: <https://images.kiwi.com/airlines/64/AT.png>

On 2026-10-23, all 37 responses were verified as 64 × 64 pixel images, reviewed visually, and checked for duplicate placeholders. Each has distinct image content. The original AF reference image is retained separately to match the supplied document.

Images are downloaded during development, decoded and saved as PNG without visual alterations. Files are bounded by image dimensions and size, checked for blank responses, and visually reviewed before inclusion in `manifest.json`. The manifest contains only local filenames, keyed by the exact two-character IATA carrier code. No image is fetched while generating or downloading a customer document.

This is a curated set, not a complete global airline catalog. Unknown carriers must retain their recorded name or code as text instead of receiving a guessed logo. Refreshing these assets requires another explicit development-time review of the source images.

## Rejected previous provider

The previous application used this Daisycon source pattern:

```text
https://images.daisycon.io/airline/?width=300&height=150&iata={lowercase_iata_code}
```

Provider documentation: <https://daisycon.com/en/developers/productfeeds/product-images/airline-logos/>

The 2026-10-23 packaging attempt returned the same generic aircraft-window placeholder for all 37 requested codes. Every response from that attempt was excluded after visual review and duplicate-pixel comparison. None is packaged here.
