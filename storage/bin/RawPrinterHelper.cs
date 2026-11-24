// tp-rawprint — Raw spooler helper for Temu Pulang POS
// Usage: tp-rawprint.exe --printer="POS80 Printer" --file="path\to\payload.bin"
//
// Exit codes:
//   0  All bytes written + EndDocPrinter succeeded
//   1  Invalid CLI arguments
//   2  File missing / unreadable
//   3  OpenPrinter failed
//   4  StartDocPrinter / StartPagePrinter failed
//   5  WritePrinter partial write or error mid-stream
//   6  EndPagePrinter / EndDocPrinter failed after bytes written
//   7  Unhandled exception after OpenPrinter succeeded

using System;
using System.IO;
using System.Runtime.InteropServices;
using System.Text;

class RawPrinterHelper
{
    // ── Win32 P/Invoke declarations ───────────────────────────────────────────

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct DOC_INFO_1
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDatatype;
    }

    [DllImport("winspool.Drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, IntPtr pDefault);

    [DllImport("winspool.Drv", EntryPoint = "ClosePrinter", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern int StartDocPrinter(IntPtr hPrinter, int level, ref DOC_INFO_1 di);

    [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    // ─────────────────────────────────────────────────────────────────────────

    static int Main(string[] args)
    {
        string printerName = null;
        string filePath = null;

        foreach (string arg in args)
        {
            if (arg.StartsWith("--printer=", StringComparison.OrdinalIgnoreCase))
                printerName = arg.Substring("--printer=".Length).Trim('"');
            else if (arg.StartsWith("--file=", StringComparison.OrdinalIgnoreCase))
                filePath = arg.Substring("--file=".Length).Trim('"');
        }

        if (string.IsNullOrEmpty(printerName) || string.IsNullOrEmpty(filePath))
        {
            Console.Error.WriteLine("ERR: Usage: tp-rawprint.exe --printer=\"<name>\" --file=\"<path>\"");
            return 1;
        }

        byte[] payload;
        try
        {
            if (!File.Exists(filePath))
            {
                Console.Error.WriteLine("ERR: File not found: " + filePath);
                return 2;
            }
            payload = File.ReadAllBytes(filePath);
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine("ERR: Cannot read file: " + ex.Message);
            return 2;
        }

        IntPtr hPrinter = IntPtr.Zero;
        bool printerOpen = false;
        bool docStarted = false;
        bool pageStarted = false;
        bool anyBytesWritten = false;

        try
        {
            if (!OpenPrinter(printerName, out hPrinter, IntPtr.Zero))
            {
                Console.Error.WriteLine("ERR: OpenPrinter failed. Win32 error: " + Marshal.GetLastWin32Error());
                return 3;
            }
            printerOpen = true;

            var di = new DOC_INFO_1
            {
                pDocName = "TP-Receipt",
                pOutputFile = null,
                pDatatype = "RAW"
            };

            int docId = StartDocPrinter(hPrinter, 1, ref di);
            if (docId <= 0)
            {
                Console.Error.WriteLine("ERR: StartDocPrinter failed. Win32 error: " + Marshal.GetLastWin32Error());
                return 4;
            }
            docStarted = true;

            if (!StartPagePrinter(hPrinter))
            {
                Console.Error.WriteLine("ERR: StartPagePrinter failed. Win32 error: " + Marshal.GetLastWin32Error());
                return 4;
            }
            pageStarted = true;

            // Write all bytes in a loop — partial writes are treated as uncertain
            int offset = 0;
            int remaining = payload.Length;
            GCHandle pinnedArray = GCHandle.Alloc(payload, GCHandleType.Pinned);
            try
            {
                while (remaining > 0)
                {
                    IntPtr ptr = IntPtr.Add(pinnedArray.AddrOfPinnedObject(), offset);
                    int written;
                    if (!WritePrinter(hPrinter, ptr, remaining, out written))
                    {
                        Console.Error.WriteLine("ERR: WritePrinter failed at offset " + offset + ". Win32 error: " + Marshal.GetLastWin32Error());
                        return anyBytesWritten ? 5 : 5;
                    }
                    if (written <= 0)
                    {
                        Console.Error.WriteLine("ERR: WritePrinter returned 0 bytes written at offset " + offset);
                        return 5;
                    }
                    anyBytesWritten = true;
                    offset += written;
                    remaining -= written;
                }
            }
            finally
            {
                pinnedArray.Free();
            }

            // End page and document
            bool endPageOk = EndPagePrinter(hPrinter);
            pageStarted = false;
            bool endDocOk = EndDocPrinter(hPrinter);
            docStarted = false;

            if (!endPageOk || !endDocOk)
            {
                Console.Error.WriteLine("ERR: EndPage/EndDoc failed. Win32 errors: page=" + (endPageOk ? "ok" : Marshal.GetLastWin32Error().ToString()) + " doc=" + (endDocOk ? "ok" : Marshal.GetLastWin32Error().ToString()));
                return 6;
            }

            Console.WriteLine("OK: " + payload.Length + " bytes spooled to \"" + printerName + "\"");
            return 0;
        }
        catch (Exception ex)
        {
            Console.Error.WriteLine("ERR: Unhandled exception: " + ex.Message);
            return printerOpen ? 7 : 3;
        }
        finally
        {
            if (hPrinter != IntPtr.Zero)
                ClosePrinter(hPrinter);
        }
    }
}

