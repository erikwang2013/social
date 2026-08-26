# PHP gRPC रनटाइम वातावरण

**语言 / Languages:** [中文](README.grpcs.md) · [English](README.grpcs.en.md) · [한국어](README.grpcs.ko.md) · [Русский](README.grpcs.ru.md) · [Deutsch](README.grpcs.de.md) · [Français](README.grpcs.fr.md) · [Español](README.grpcs.es.md) · [Português](README.grpcs.pt.md) · [हिन्दी](README.grpcs.hi.md) · [العربية](README.grpcs.ar.md) · [বাংলা](README.grpcs.bn.md) · [Bahasa Indonesia](README.grpcs.id.md) · [日本語](README.grpcs.ja.md)

यह डायरेक्टरी webman gRPC **क्लाइंट** प्रोजेक्ट है (कॉन्ट्रैक्ट स्टब्स `generated/` में हैं, जो `scripts/gen-contracts.sh` द्वारा जनरेट होते हैं)।

## निर्भरताएँ

- Composer पैकेज: `grpc/grpc` (PHP क्लाइंट लाइब्रेरी), `google/protobuf` (मैसेज रनटाइम) — पहले से `composer.json` में जोड़े गए हैं।
- PHP एक्सटेंशन: `grpc` (आवश्यक; क्लाइंट कनेक्शन C एक्सटेंशन पर निर्भर करते हैं)। `google/protobuf` एक्सटेंशन वैकल्पिक है (उपलब्ध होने पर एक्सटेंशन को प्राथमिकता दें, अन्यथा Composer पैकेज उपयोग करें)।

## grpc एक्सटेंशन इंस्टॉल करना

```bash
php -m | grep grpc || sudo pecl install grpc   # php_dir 属 root，pecl 需 sudo
```

इंस्टॉल करने के बाद पुष्टि करें कि `php -i | grep grpc` `grpc support => enabled` दिखाता है।

वर्तमान डेव मशीन (2026-08-17): Composer पैकेज इंस्टॉल हैं (grpc/grpc 1.82, google/protobuf 5.35), **grpc एक्सटेंशन इंस्टॉल नहीं है** (pecl के पास लिखने की अनुमति नहीं, sudo के लिए पासवर्ड चाहिए)। स्थानीय रूप से gRPC चलाने से पहले इसे इंस्टॉल करना आवश्यक है; CI (T10) इसे shivammathur/setup-php के `extensions: grpc` से इंस्टॉल करता है।

## नोट्स

इस रिपॉज़िटरी में `composer require` `erikwang2013/security-php` प्लगइन में डुप्लीकेट क्लास लोडिंग का फ़ैटल एरर ट्रिगर करता है
(vendor में Installer.php प्लगइन मैकेनिज़्म द्वारा एक बार और autoload द्वारा एक बार लोड होता है); इससे बचने के लिए `--no-plugins` जोड़ें:

```bash
composer require grpc/grpc google/protobuf --no-interaction --no-plugins
```

## प्रोब स्क्रिप्ट

`php scripts/probe_ping.php` (T5 द्वारा प्रदान) infrastructure के `127.0.0.1:50051` पर `InfraService.Ping` भेजता है।
