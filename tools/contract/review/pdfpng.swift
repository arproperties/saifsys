import PDFKit
import AppKit
let args = CommandLine.arguments
let doc = PDFDocument(url: URL(fileURLWithPath: args[1]))!
let outDir = args[2]
let pages = args[3].split(separator: ",").map { Int($0)! }
for p in pages {
    guard let page = doc.page(at: p - 1) else { continue }
    let r = page.bounds(for: .mediaBox)
    let scale: CGFloat = 1.3
    let img = NSImage(size: NSSize(width: r.width*scale, height: r.height*scale))
    img.lockFocus()
    NSColor.white.set(); NSRect(x:0,y:0,width:r.width*scale,height:r.height*scale).fill()
    let ctx = NSGraphicsContext.current!.cgContext
    ctx.scaleBy(x: scale, y: scale)
    page.draw(with: .mediaBox, to: ctx)
    img.unlockFocus()
    let rep = NSBitmapImageRep(data: img.tiffRepresentation!)!
    try! rep.representation(using: .png, properties: [:])!.write(to: URL(fileURLWithPath: "\(outDir)/p\(p).png"))
}
print("done", doc.pageCount)
