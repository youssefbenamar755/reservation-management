import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'

const source = readFileSync(new URL('../lib/whatsapp.ts', import.meta.url), 'utf8')
const { outputText } = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
})
const module = { exports: {} }
runInNewContext(outputText, { module, exports: module.exports })
const { whatsappUrl, isPhoneField } = module.exports

test('WhatsApp links normalize international prefixes and formatting while preserving internal zeroes', () => {
  for (const number of ['+1 202 555 0100', '0012025550100', '12025550100', '  +1 (202) 555-0100  ', '+1.202.555.0100', '+1\u00a0202\u202f555 0100']) {
    assert.equal(whatsappUrl(number), 'https://wa.me/12025550100')
  }
  assert.equal(whatsappUrl('+44 7700 900123'), 'https://wa.me/447700900123')
  assert.equal(whatsappUrl('0044 7700 900123'), 'https://wa.me/447700900123')
})

test('WhatsApp links accept only bounded positive international digits and safe numeric scalars', () => {
  assert.equal(whatsappUrl('1234567'), 'https://wa.me/1234567')
  assert.equal(whatsappUrl('+123456789012345'), 'https://wa.me/123456789012345')
  assert.equal(whatsappUrl(12025550100), 'https://wa.me/12025550100')
  for (const number of ['123456', '+1234567890123456', '07700900123', '+07700900123', '+00447700900123', '0000000', 123456, -12025550100, 12025550100.5, Number.NaN, Number.POSITIVE_INFINITY, Number.MAX_SAFE_INTEGER + 1]) {
    assert.equal(whatsappUrl(number), null, `Unexpected link for ${String(number)}`)
  }
})

test('WhatsApp links reject extensions, mixed text, multiple numbers, URLs, and non-phone types', () => {
  for (const value of [
    null, undefined, true, false, {}, [], ['+12025550100'], { phone: '+12025550100' },
    '', '   ', '+', '++12025550100', '1202+5550100', '1-800-FLOWERS',
    '+12025550100 ext. 12', '+12025550100 x12', '+12025550100#12',
    '+12025550100;ext=12', '12025550100,5', '+12025550100/+447700900123',
    'tel:+12025550100', 'https://wa.me/12025550100', 'javascript:12025550100',
    '<script>12025550100</script>',
  ]) {
    assert.equal(whatsappUrl(value), null)
  }
})

test('phone fields recognize form keys, numeric suffixes, schema labels, camel case, and accents', () => {
  for (const key of ['phone', 'phone_2', 'phoneNumber', 'customerMobileNumber', 'telephone-number', 'mobile', 'cell', 'cellPhone', 'whatsapp', 'whatsapp_3', 'tel']) {
    assert.equal(isPhoneField({ key, label: 'Contact details' }), true, key)
  }
  for (const label of ['Phone number', 'Mobile number', 'Cell phone', 'Telephone', 'WhatsApp number', 'Whats App number', 'Téléphone', 'Numéro de téléphone', 'Customer TEL']) {
    assert.equal(isPhoneField({ key: 'input_text', label }), true, label)
  }
})

test('phone-field matching does not make unrelated numeric fields or similar words clickable', () => {
  for (const field of [
    { key: 'cancellation', label: 'Cancellation reference' },
    { key: 'microphone', label: 'Microphone option' },
    { key: 'hotel', label: 'Hotel reference' },
    { key: 'automobile', label: 'Vehicle number' },
    { key: 'cellular_plan', label: 'Data allowance' },
    { key: 'phonebook', label: 'Address book entry' },
    { key: 'order_id', label: 'Order number' },
    { key: 'pnr', label: 'Booking reference' },
    { key: 'passport', label: 'Passport number' },
    { key: '', label: '' },
  ]) {
    assert.equal(isPhoneField(field), false, field.key)
  }
})
