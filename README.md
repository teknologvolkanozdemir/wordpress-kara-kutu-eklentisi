# WordPress Kara Kutu

WordPress yönetimindeki önemli etkinlikleri kaydeder ve yönetim panelindeki **Kara Kutu** sayfasında yöneticilere (`manage_options`) gösterir. Eklenti; kullanıcı girişleri, kullanıcı/rol değişiklikleri, içerik ve yorum işlemleri, ortam ekleme/silme, ayar değişiklikleri, eklenti ve tema değişiklikleri ile güncelleme tamamlanmalarını kaydeder.

## Kurulum

1. Eklenti dosyasını `wp-content/plugins/wordpress-kara-kutu-eklentisi/` dizinine yerleştirin.
2. Eklentiyi WordPress yönetim panelinden etkinleştirin.
3. Yönetim panelindeki **Kara Kutu** menüsünden kayıtları inceleyin.

Kayıtlar WordPress veritabanında ayrı bir tabloda saklanır, sayfalandırılarak gösterilir ve bu eklentide silme/temizleme işlevi bulunmaz. Eklentiyi devre dışı bırakmak kayıtları silmez.

Bu kayıt sistemi WordPress'in kancalarına dayanır; her özel eklenti veya sunucu düzeyindeki işlemi yakalayacağı garanti edilemez. Ayrıca veritabanına doğrudan erişimi olan bir kişi kayıtları değiştirebilir veya silebilir; mutlak değiştirilemezlik için veritabanı erişim denetimleri ve harici yedekleme gerekir. Kısa ömürlü transient ayarları gürültüyü azaltmak için kaydedilmez.
